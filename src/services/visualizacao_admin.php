<?php
declare(strict_types=1);

const AVISO_PERMISSAO_ADMIN = 'Você não tem permissão para fazer isso. Consulte diretamente um laboratorista.';

/** A visualização nunca substitui o perfil real do administrador. */
function adminAcaoRestrita(string $script, string $metodo, array $post, array $get, bool $visualizando): bool
{
    $arquivo = basename($script);
    $escrita = !in_array(strtoupper($metodo), ['GET', 'HEAD'], true);
    if (str_contains($script, '/pages_laboratorista/')) {
        // Somente o catálogo e suas categorias permitem edição pelo administrador.
        $catalogo = ['catalogo.php', 'cadastrar_catalogo.php', 'cadastrar_categoria.php'];
        if (!in_array($arquivo, $catalogo, true) && ($escrita || isset($get['action']) || isset($get['acao']))) return true;
    }
    if (str_contains($script, '/pages_aluno/')) {
        if ($escrita) return true;
    }
    if (str_contains($script, '/api/')) {
        // buscar também pode criar uma nota no fluxo atual de atraso: não é leitura pura.
        if (in_array($arquivo, ['pedido_arquivar.php', 'pedido_arquivar_lab.php', 'pedido_atraso_nota.php'], true)) return true;
        if ($visualizando && $escrita && in_array($arquivo, ['admin_apagar_pedido.php', 'admin_notificar_lab.php'], true)) return true;
    }
    return false;
}
