# Paket-Metadaten für die Contao-Extension-Liste

Titel, Beschreibung und Keywords für https://extensions.contao.org und den Contao Manager.
Das hier ist die Arbeitskopie – **wirksam wird eine Änderung erst über einen gemergten Pull
Request** gegen [contao/package-metadata](https://github.com/contao/package-metadata), Ordner
`meta/bluebranch/chatbot/`. Danach wird alle 30 Minuten neu indexiert.

Ablauf bei einer Textänderung:

1. `de.yml` / `en.yml` hier bearbeiten
2. Nach `contao/package-metadata` kopieren und als PR einreichen
3. Der Linter dort prüft unter anderem die Rechtschreibung; unbekannte Wörter entweder
   umformulieren oder in `linter/allowlists/` eintragen

Lokal prüfen (im Checkout von `contao/package-metadata`):

```bash
composer install --working-dir=linter
php linter/lint meta/bluebranch/chatbot/*.yml
```

Letzter Stand: https://github.com/contao/package-metadata/pull/795
