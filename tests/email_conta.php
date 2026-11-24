<?php
declare(strict_types=1);
/** Integração local real (HTTP + MySQL + saída local). Não chama SIGAA nem envia e-mails reais. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ob_start();
require_once __DIR__ . '/../src/services/sessao.php';
loadEnv(dirname(__DIR__) . '/.env');
$base = rtrim((string) env('APP_URL'), '/');
if (env('APP_ENV') !== 'local' || env('DB_PROFILE') !== 'xampp' || emailTransporte() !== 'local'
    || !in_array(env('DB_HOST'), ['localhost','127.0.0.1'], true)
    || !in_array(parse_url($base, PHP_URL_HOST), ['localhost','127.0.0.1'], true)) {
    throw new RuntimeException('Teste permitido somente no XAMPP com transporte local de e-mail.');
}
$pdo = db();
$ids = []; $sessoes = []; $clientes = []; $checks = 0;
$prefix = 'email-teste-' . bin2hex(random_bytes(6));
$password = bin2hex(random_bytes(16));
$originais = $pdo->query('SELECT id_user,nome,login,email,tipo_perfil,email_verificado_em,nome_confirmado_em,email_versao,cadastro_sigaa FROM Usuario ORDER BY id_user')->fetchAll();
$limitesOriginais = $pdo->query('SELECT chave FROM Email_Limite')->fetchAll(PDO::FETCH_COLUMN);
function emailOk(bool $ok, string $texto): void {
    global $checks;
    if (!$ok) throw new RuntimeException('FALHOU: ' . $texto);
    $checks++; echo "OK: $texto\n";
}
function emailFixture(bool $sigaa = false, bool $google = false): array {
    global $pdo, $ids, $prefix, $password;
    $login = $prefix . '-' . count($ids);
    if ($sigaa) $login = '999.999.' . str_pad((string) random_int(0,999),3,'0',STR_PAD_LEFT) . '-' . random_int(10,99);
    $pdo->prepare("INSERT INTO Usuario(nome,login,hash_senha,tipo_perfil,google_sub)
        VALUES (?,?,?,'estudante',?)")->execute([$sigaa ? 'Estudante SIGAA' : 'Nome local de teste', $login,
            password_hash($password, PASSWORD_DEFAULT), $google ? $prefix . '-google' : null]);
    $id = (int) $pdo->lastInsertId(); $ids[] = $id;
    return contaUsuario($pdo, $id);
}
function emailCliente(array $u, string $origem = 'teste'): array {
    global $clientes, $sessoes, $base;
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    session_id(''); iniciarSessaoSegura(); $_SESSION = [];
    $destino = criarSessaoUsuario($u, $origem);
    $csrf = emailTokenCsrf();
    $sid = session_id(); $sessoes[] = $sid; session_write_close();
    $c = curl_init();
    curl_setopt_array($c, [CURLOPT_COOKIEFILE => '', CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 20, CURLOPT_INTERFACE => '127.0.0.2']);
    curl_setopt($c, CURLOPT_COOKIELIST, parse_url($base, PHP_URL_HOST) . "\tFALSE\t/\tFALSE\t0\tPHPSESSID\t$sid");
    $clientes[] = $c;
    return [$c, $destino, $csrf];
}
function emailHttp(CurlHandle $c, string $path, ?array $post = null, bool $json = true): array {
    global $base;
    $headers = [];
    curl_setopt_array($c, [CURLOPT_URL => $base . $path, CURLOPT_HTTPHEADER => $json ? ['Accept: application/json'] : [],
        CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$headers): int {
            if (str_contains($line, ':')) { [$key,$v]=explode(':',$line,2); $headers[strtolower(trim($key))]=trim($v); }
            return strlen($line);
        }]);
    if ($post === null) curl_setopt($c, CURLOPT_HTTPGET, true);
    else { curl_setopt($c, CURLOPT_POST, true); curl_setopt($c, CURLOPT_POSTFIELDS, http_build_query($post)); }
    $body = curl_exec($c);
    if ($body === false) throw new RuntimeException('Falha HTTP local: ' . curl_error($c));
    if (preg_match('/<b>(Warning|Fatal error|Notice)|Uncaught .*Exception/', $body)) throw new RuntimeException('Erro PHP na resposta local.');
    return ['status'=>(int)curl_getinfo($c,CURLINFO_RESPONSE_CODE),'body'=>$body,'json'=>json_decode($body,true),'headers'=>$headers];
}
function emailConfig(CurlHandle $c): array {
    $r = emailHttp($c, '/conta_email.php', null, false);
    if ($r['status'] !== 200) throw new RuntimeException('Página de e-mail indisponível: ' . $r['status']);
    preg_match('/id="contaEmailConfig" type="application\/json">(.*?)<\/script>/s',$r['body'],$m);
    return json_decode($m[1] ?? '',true,512,JSON_THROW_ON_ERROR);
}
function emailAcao(CurlHandle $c, string $csrf, string $acao, array $dados = []): array {
    return emailHttp($c,'/api/email_conta.php',array_merge(['csrf_email'=>$csrf,'acao'=>$acao],$dados));
}
function emailCodigoTeste(string $para): string {
    foreach (glob(emailDiretorioLocal().'/*.json') ?: [] as $path) {
        $mail=json_decode(file_get_contents($path),true);
        if (($mail['para']??'') === $para && preg_match('/confirmação é: ([0-9]{6})/u',$mail['mensagem'],$m)) return $m[1];
    }
    throw new RuntimeException('Código sintético não encontrado na caixa de testes.');
}
function emailDestravarReenvio(int $id): void {
    global $pdo; $pdo->prepare('UPDATE Email_Verificacao SET criado_em=? WHERE id_user=?')->execute([time()-61,$id]);
}
try {
    $sigaa = emailFixture(true);
    [$c,$destino] = emailCliente($sigaa,'sigaa');
    emailOk($destino === 'conta_email.php','SIGAA pendente abre a confirmação antes do painel');
    $conf = emailConfig($c); $csrf=$conf['csrf'];
    emailOk($conf['inicial'] && $conf['etapa']==='endereco' && $conf['popup'],'autenticação SIGAA recém-feita dispensa repetir a senha e exibe explicação');
    emailOk(!emailConfig($c)['popup'],'aviso inicial é mostrado uma vez por sessão');
    $r=emailHttp($c,'/index.php',null,false);
    emailOk($r['status']===302 && str_ends_with($r['headers']['location'],'/conta_email.php'),'URL direta não ignora o primeiro acesso');
    $r=emailHttp($c,'/pages_aluno/carrinho.php',['action'=>'finalizar-pedido']);
    emailOk($r['status']===403 && $r['json']['cadastro_pendente'],'POST direto não cria pedido sem cadastro confirmado');
    emailOk(emailAcao($c,'errado','enviar',['email'=>$prefix.'@example.invalid','nome'=>'Teste'])['status']===403,'envio exige CSRF da sessão');
    emailOk(emailHttp($c,'/api/email_conta.php')['status']===405,'endpoint de confirmação exige POST');
    emailOk(emailAcao($c,$csrf,'enviar',['email'=>$prefix.'@example.invalid','nome'=>' '])['status']===422,'nome vazio é recusado no servidor');
    emailOk(emailAcao($c,$csrf,'enviar',['email'=>"invalido\r\nBcc:outra@example.invalid",'nome'=>'Teste'])['status']===422,'endereço inválido e injeção de cabeçalho são recusados');
    $endereco=$prefix.'-primeiro@example.invalid';
    $r=emailAcao($c,$csrf,'enviar',['email'=>$endereco,'nome'=>'Nome completo confirmado','id_user'=>1,'matricula'=>'']);
    emailOk($r['status']===200 && $r['json']['ok'],'código é gerado sem exigir matrícula, descrição ou foto');
    $d=emailDesafio($pdo,(int)$sigaa['id_user']); $codigo=emailCodigoTeste($endereco);
    emailOk(strlen($d['codigo_hash'])===64 && $d['codigo_hash']!==$codigo && !str_contains($r['body'],$codigo),'banco e resposta HTTP não expõem o código em texto puro');
    emailOk(contaUsuario($pdo,(int)$sigaa['id_user'])['email']===null,'envio ainda não altera o e-mail da conta');
    emailOk(emailConfig($c)['etapa']==='codigo','recarregar mantém a etapa e o desafio da mesma sessão');
    emailOk(emailAcao($c,$csrf,'enviar',['email'=>$endereco,'nome'=>'Teste'])['status']===429,'reenvio imediato é limitado no servidor');
    [$outra] = emailCliente(contaUsuario($pdo,(int)$sigaa['id_user']),'sigaa');
    $confOutra=emailConfig($outra);
    emailOk(emailAcao($outra,$confOutra['csrf'],'confirmar',['codigo'=>$codigo])['status']===422,'outra sessão da mesma conta não usa o código da primeira');
    $errado=$codigo==='000000'?'000001':'000000';
    emailOk(emailAcao($c,$csrf,'confirmar',['codigo'=>$errado])['status']===422,'código incorreto é recusado e contado');
    $r=emailAcao($c,$csrf,'confirmar',['codigo'=>$codigo]);
    $salvo=contaUsuario($pdo,(int)$sigaa['id_user']);
    emailOk($r['status']===200 && $salvo['email']===$endereco && !empty($salvo['email_verificado_em'])
        && $salvo['nome']==='Nome completo confirmado','confirmação grava nome e e-mail verificado juntos');
    $opcionais=$pdo->query('SELECT matricula,descricao,foto_perfil,tipo_perfil FROM Usuario WHERE id_user='.(int)$sigaa['id_user'])->fetch();
    emailOk($opcionais['matricula']===null && $opcionais['descricao']===null && $opcionais['foto_perfil']===null
        && $opcionais['tipo_perfil']==='estudante','dados opcionais vazios não impedem o acesso nem alteram permissões');
    emailOk(emailDesafio($pdo,(int)$sigaa['id_user'])===null,'código é consumido na confirmação');
    emailOk(emailAcao($c,$csrf,'confirmar',['codigo'=>$codigo])['status']===403,'reutilização do código ou CSRF antigo é recusada');
    emailOk(emailHttp($c,'/index.php',null,false)['status']===200,'cadastro completo libera o painel');
    emailOk(emailAcao($outra,$confOutra['csrf'],'enviar',['email'=>$prefix.'-velho@example.invalid','nome'=>'Teste'])['status']===403,'mudança confirmada invalida autorizações abertas em outras sessões');

    $local=emailFixture(); [$lc] = emailCliente($local); $cf=emailConfig($lc); $ct=$cf['csrf'];
    emailOk(emailHttp($lc,'/login.php',['tipo_acesso'=>'teste','usuario'=>$local['login'],'senha'=>$password])['status']===403,
        'formulário de login também recusa POST sem CSRF');
    emailOk($cf['etapa']==='identidade' && !$cf['inicial'],'troca posterior exige confirmar a identidade');
    emailOk(emailAcao($lc,$ct,'enviar',['email'=>$prefix.'-local@example.invalid'])['status']===403,'envio direto sem reautenticação é recusado');
    emailOk(emailAcao($lc,$ct,'autenticar',['login'=>$sigaa['login'],'senha'=>$password])['status']===422,'credenciais de outra conta não autorizam a troca');
    emailOk(emailAcao($lc,$ct,'autenticar',['login'=>$local['login'],'senha'=>'invalida'])['status']===422,'senha incorreta não autoriza a troca');
    emailOk(emailAcao($lc,$ct,'autenticar',['login'=>$local['login'],'senha'=>$password])['status']===200,'conta local confirma a identidade com password_verify');
    $primeiro=$prefix.'-local1@example.invalid';
    emailOk(emailAcao($lc,$ct,'enviar',['email'=>$primeiro])['status']===200,'troca começa com envio ao endereço pendente');
    $codigo1=emailCodigoTeste($primeiro);
    emailDestravarReenvio((int)$local['id_user']);
    $segundo=$prefix.'-local2@example.invalid';
    emailOk(emailAcao($lc,$ct,'enviar',['email'=>$segundo])['status']===200,'novo endereço emite outro desafio');
    $codigo2=emailCodigoTeste($segundo);
    if ($codigo1!==$codigo2) emailOk(emailAcao($lc,$ct,'confirmar',['codigo'=>$codigo1])['status']===422,'código anterior não vale para o novo endereço');
    emailOk(emailAcao($lc,$ct,'confirmar',['codigo'=>$codigo2])['status']===200,'apenas o novo código confirma o endereço correto');

    $perfil=emailHttp($lc,'/perfil.php',null,false);
    preg_match('/name="csrf_perfil" value="([a-f0-9]+)"/',$perfil['body'],$m); $cp=$m[1];
    foreach (['/api/perfil_atualizar.php','/pages_aluno/update_perfil.php'] as $path) {
        $r=emailHttp($lc,$path,['csrf_perfil'=>$cp,'email'=>$prefix.'-forjado@example.invalid','nome'=>'Não deve salvar']);
        emailOk($r['status']===422 && contaUsuario($pdo,(int)$local['id_user'])['email']===$segundo,'edição comum e endereço legado não substituem e-mail verificado: '.$path);
    }
    emailOk(str_contains($perfil['body'],'Trocar e-mail da conta') && !str_contains($perfil['body'],'id="perfilEmail" name="email"'),'modal mantém e-mail protegido e oferece botão de troca');

    // Alteração posterior notifica o endereço anteriormente verificado.
    $ct=emailConfig($lc)['csrf'];
    emailOk(emailAcao($lc,$ct,'autenticar',['login'=>$local['login'],'senha'=>$password])['status']===200,'segunda troca exige nova confirmação de identidade');
    $terceiro=$prefix.'-local3@example.invalid';
    emailAcao($lc,$ct,'enviar',['email'=>$terceiro]);
    emailAcao($lc,$ct,'confirmar',['codigo'=>emailCodigoTeste($terceiro)]);
    $aviso=false;
    foreach (glob(emailDiretorioLocal().'/*.json') ?: [] as $path) {
        $mail=json_decode(file_get_contents($path),true);
        if (($mail['para']??'')===$segundo && str_contains($mail['assunto'],'foi alterado')) $aviso=true;
    }
    emailOk($aviso,'endereço anterior recebe aviso de alteração após confirmação');

    $limite=emailFixture(); [$limc]=emailCliente($limite); $t=emailConfig($limc)['csrf'];
    emailAcao($limc,$t,'autenticar',['login'=>$limite['login'],'senha'=>$password]);
    $alvo=$prefix.'-limite@example.invalid'; emailAcao($limc,$t,'enviar',['email'=>$alvo]); $cc=emailCodigoTeste($alvo);
    $wrong=$cc==='111111'?'222222':'111111';
    for($i=0;$i<5;$i++) emailOk(emailAcao($limc,$t,'confirmar',['codigo'=>$wrong])['status']===422,'tentativa incorreta limitada '.($i+1));
    emailOk(emailAcao($limc,$t,'confirmar',['codigo'=>$cc])['status']===422,'nem o código certo vale após esgotar as tentativas');
    emailDestravarReenvio((int)$limite['id_user']);
    $alvo=$prefix.'-expirado@example.invalid'; emailAcao($limc,$t,'enviar',['email'=>$alvo]); $cc=emailCodigoTeste($alvo);
    $pdo->prepare('UPDATE Email_Verificacao SET expira_em=? WHERE id_user=?')->execute([time()-1,$limite['id_user']]);
    emailOk(emailAcao($limc,$t,'confirmar',['codigo'=>$cc])['status']===422,'código vencido é recusado no servidor');

    $forca=emailFixture(); [$fc]=emailCliente($forca); $ft=emailConfig($fc)['csrf'];
    for($i=0;$i<5;$i++) emailAcao($fc,$ft,'autenticar',['login'=>$forca['login'],'senha'=>'errada']);
    emailOk(emailAcao($fc,$ft,'autenticar',['login'=>$forca['login'],'senha'=>$password])['status']===429,'reautenticação tem limite persistente por usuário');
    [$fc2]=emailCliente($forca); $ft2=emailConfig($fc2)['csrf'];
    emailOk(emailAcao($fc2,$ft2,'autenticar',['login'=>$forca['login'],'senha'=>$password])['status']===429,'nova sessão não zera o limite de autenticação');

    $google=emailFixture(false,true); [$gc,,$gcsrf]=emailCliente($google,'google');
    emailOk(emailHttp($gc,'/conta_email.php',null,false)['status']===302,'conta Google não abre troca SIGAA/local');
    // Usa CSRF válido gerado na sessão, mas a identidade Google continua protegida no servidor.
    emailOk(emailAcao($gc,$gcsrf,'autenticar',['login'=>$google['login'],'senha'=>$password])['status']===403,'troca de conta Google é recusada mesmo com CSRF válido');
    $pdo->prepare('UPDATE Usuario SET email_verificado_em=NULL WHERE id_user=?')->execute([$sigaa['id_user']]);
    [$antiga,$redirect]=emailCliente(contaUsuario($pdo,(int)$sigaa['id_user']),'teste');
    emailOk($redirect==='conta_email.php' && emailHttp($antiga,'/index.php',null,false)['status']===302,'login local não contorna exigência persistente da conta SIGAA');

    // Falha de SMTP não deve instalar um desafio ou substituir e-mail.
    $falha=emailFixture();
    if (session_status()===PHP_SESSION_ACTIVE) session_write_close();
    session_id(''); iniciarSessaoSegura(); $_SESSION=['auth_user'=>['id'=>$falha['id_user'],'origem'=>'teste']];
    emailAutorizar($falha); $sessoes[]=session_id();
    $transporteAnterior=$_ENV['MAIL_TRANSPORT']; $_ENV['MAIL_TRANSPORT']='smtp';
    // O desenvolvedor pode ter SMTP real configurado: o cenário negativo nunca deve autenticar ou enviar.
    $senhaSmtpAnterior=$_ENV['SMTP_PASSWORD'] ?? null; $_ENV['SMTP_PASSWORD']='';
    $semEnvio=false;
    try { emailEnviarCodigo($pdo,(int)$falha['id_user'],$prefix.'-sem-smtp@example.invalid',''); }
    catch (RuntimeException) { $semEnvio=true; }
    $_ENV['MAIL_TRANSPORT']=$transporteAnterior;
    if ($senhaSmtpAnterior === null) unset($_ENV['SMTP_PASSWORD']); else $_ENV['SMTP_PASSWORD']=$senhaSmtpAnterior;
    emailOk($semEnvio && emailDesafio($pdo,(int)$falha['id_user'])===null
        && contaUsuario($pdo,(int)$falha['id_user'])['email']===null,'SMTP sem credenciais falha sem alterar o usuário ou emitir código válido');
    $_SESSION['email_autorizacao']['expira']=time()-1;
    $expirou=false; try { emailEnviarCodigo($pdo,(int)$falha['id_user'],$prefix.'-vencido@example.invalid',''); }
    catch(ErroEmailConta $e) { $expirou=$e->status===403; }
    emailOk($expirou,'autorização de identidade vencida exige nova autenticação');
    session_write_close();

    $soNome=emailFixture(true);
    $pdo->prepare('UPDATE Usuario SET email=?,email_verificado_em=UTC_TIMESTAMP() WHERE id_user=?')
        ->execute([$prefix.'-ja-verificado@example.invalid',$soNome['id_user']]);
    [$nc]=emailCliente(contaUsuario($pdo,(int)$soNome['id_user']),'sigaa'); $nt=emailConfig($nc)['csrf'];
    $r=emailAcao($nc,$nt,'enviar',['email'=>$prefix.'-ja-verificado@example.invalid','nome'=>'Nome agora confirmado']);
    emailOk($r['status']===200 && isset($r['json']['destino']) && !contaCadastroPendente(contaUsuario($pdo,(int)$soNome['id_user'])),
        'quando só o nome está pendente, preserva o e-mail já verificado sem exigir outro código');

    $antes=$_ENV['APP_ENV']; $_ENV['APP_ENV']='production';
    $bloqueou=false; try { emailTransporte(); } catch (RuntimeException) { $bloqueou=true; }
    $_ENV['APP_ENV']=$antes;
    emailOk($bloqueou,'produção recusa transporte local que poderia simular entrega');
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if(session_status()===PHP_SESSION_ACTIVE) session_write_close();
    foreach($clientes as $c) {
        foreach(curl_getinfo($c,CURLINFO_COOKIELIST) ?: [] as $cookie) {
            $partes=explode("\t",$cookie); if(($partes[5]??'')==='PHPSESSID') $sessoes[]=$partes[6];
        }
        curl_close($c);
    }
    foreach(array_unique($sessoes) as $sid) {
        if(preg_match('/^[a-zA-Z0-9,-]+$/D',$sid)) @unlink(rtrim(session_save_path(),'/\\').'/sess_'.$sid);
    }
    foreach($ids as $id) {
        $pdo->prepare('DELETE FROM Log_Auditoria WHERE id_user=?')->execute([$id]);
        $pdo->prepare('DELETE FROM Usuario WHERE id_user=?')->execute([$id]);
        foreach(['envio','identidade','codigo'] as $acao) {
            $chave=hash_hmac('sha256',"$acao|usuario|$id",emailChave());
            $pdo->prepare('DELETE FROM Email_Limite WHERE chave=?')->execute([$chave]);
        }
    }
    foreach(['envio','identidade','codigo'] as $acao) {
        $chave=hash_hmac('sha256',"$acao|ip|127.0.0.2",emailChave());
        if(!in_array($chave,$limitesOriginais,true)) $pdo->prepare('DELETE FROM Email_Limite WHERE chave=?')->execute([$chave]);
    }
    foreach(glob(emailDiretorioLocal().'/*.json') ?: [] as $path) {
        $mail=json_decode(file_get_contents($path),true);
        if(str_starts_with((string)($mail['para']??''),$prefix)) unlink($path);
    }
}
emailOk($pdo->query('SELECT id_user,nome,login,email,tipo_perfil,email_verificado_em,nome_confirmado_em,email_versao,cadastro_sigaa FROM Usuario ORDER BY id_user')->fetchAll()===$originais,
    'usuários originais preservados; contas, desafios e mensagens de teste removidos');
echo "\n$checks verificações de e-mail passaram. Nenhuma mensagem real foi enviada.\n";
ob_end_flush();
