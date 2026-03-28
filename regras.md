# Regras do Projeto C.I.R.C.U.I.T.O

## Foto de Perfil

- **Diretório no servidor:** `/opt/lampp/htdocs/C.I.R.C.U.I.T.O/public/assets/img/perfil/`
- **Caminho salvo no BD** (`Usuario.foto_perfil`): `/C.I.R.C.U.I.T.O/public/assets/img/perfil/{filename}`
- **Formato do nome do arquivo:** `user_{id_user}_{hex_aleatório}.{ext}`
- **Formatos aceitos:** JPG, PNG, WEBP, GIF (validado por MIME real via `finfo`)
- **Tamanho máximo:** 5 MB
- **A foto antiga deve ser apagada do disco** sempre que uma nova for enviada
- **Deleção da foto antiga** — lógica para suportar caminhos absolutos e legados:
  ```php
  $path = str_starts_with($foto_antiga, '/')
      ? '/opt/lampp/htdocs' . $foto_antiga
      : '/opt/lampp/htdocs/C.I.R.C.U.I.T.O/public/' . $foto_antiga;
  if (is_file($path)) @unlink($path);
  ```
- **Arquivos que fazem upload de foto de perfil:**
  - `public/pages_aluno/upload_foto.php`
  - `public/pages_aluno/update_perfil.php`
  - `public/pages_admin/controlar_aluno.php`

---

## Foto de Componente (Item)

- **Diretório no servidor:** `/opt/lampp/htdocs/C.I.R.C.U.I.T.O/public/assets/img/componentes/`
- **Caminho salvo no BD** (`Componente.imagem_url`): `/C.I.R.C.U.I.T.O/public/assets/img/componentes/{filename}`
- **Formato do nome do arquivo:** `comp_{uniqid(true)}.{ext}`
- **Formatos aceitos:** JPG, PNG, GIF, WEBP
- **Tamanho máximo:** 5 MB
- **A imagem antiga deve ser apagada** ao trocar por uma nova
- **Arquivos que fazem upload de foto de componente:**
  - `public/pages_laboratorista/cadastrar_catalogo.php`
  - `public/pages_laboratorista/catalogo.php`
