<?php

// Hinweis: onDeleteSearchEntry wird bereits über das #[AsCallback]-Attribut in
// IndexPageListener registriert. Hier NICHT zusätzlich manuell eintragen, sonst
// wird der Callback doppelt ausgeführt (siehe Contao\CoreBundle\EventListener\
// DataContainerCallbackListener, das über den loadDataContainer-Hook in dasselbe
// TL_DCA-Array schreibt).
