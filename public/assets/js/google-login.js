let googleLoginEmAndamento = false;
let googleLoginInicializado = false;
function falhaCarregamentoGoogle() {
    document.getElementById('googleNote').textContent = 'Não foi possível carregar o acesso Google. Recarregue a página.';
}
function iniciarGoogleLogin() {
    if (googleLoginInicializado || !window.google?.accounts?.id) return;
    const config = JSON.parse(document.getElementById('googleConfig').textContent);
    if (!config.clientId) return;
    googleLoginInicializado = true;
    const destino = document.getElementById('googleButton');
    google.accounts.id.initialize({
        client_id: config.clientId, nonce: config.nonce, hd: config.domain,
        auto_select: false, ux_mode: 'popup', callback: receberContaGoogle
    });
    destino.hidden = false;
    google.accounts.id.renderButton(destino, { type: 'standard', theme: 'outline', size: 'large', text: 'signin_with', shape: 'rectangular', locale: 'pt-BR', width: Math.min(340, Math.floor(destino.clientWidth)) });
    document.getElementById('googlePlaceholder').hidden = true;
    document.getElementById('googleNote').hidden = true;
}
async function receberContaGoogle(respostaGoogle) {
    if (googleLoginEmAndamento) return;
    googleLoginEmAndamento = true;
    const erro = document.getElementById('googleError');
    erro.hidden = true;
    try {
        const config = JSON.parse(document.getElementById('googleConfig').textContent);
        const resposta = await fetch('google_login.php', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': config.csrf, 'Accept': 'application/json' },
            body: JSON.stringify({ credential: respostaGoogle.credential })
        });
        const dados = await resposta.json();
        if (typeof dados.redirect === 'string' && ['login.php', 'index.php', 'pages_admin/index.php', 'pages_laboratorista/index.php'].includes(dados.redirect)) {
            window.location.assign(dados.redirect);
            return;
        }
        erro.textContent = dados.error || 'Não foi possível entrar. Recarregue a página e tente novamente.';
        erro.hidden = false;
    } catch (_) {
        erro.textContent = 'Falha de conexão. Recarregue a página e tente novamente.';
        erro.hidden = false;
    } finally {
        googleLoginEmAndamento = false;
    }
}
window.addEventListener('load', iniciarGoogleLogin);
