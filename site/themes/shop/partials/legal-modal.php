<?php
$legalModalData = [
    'privacy' => HfsLegal::contentForModal('privacy'),
    'terms' => HfsLegal::contentForModal('terms'),
];
?>
<div class="hfs-legal" id="hfs-legal" hidden aria-hidden="true">
    <div class="hfs-legal__backdrop" data-hfs-legal-close tabindex="-1"></div>
    <div class="hfs-legal__dialog" role="dialog" aria-modal="true" aria-labelledby="hfs-legal-title" tabindex="-1">
        <button type="button" class="hfs-legal__close" data-hfs-legal-close aria-label="Kapat">&times;</button>
        <h2 id="hfs-legal-title" class="hfs-legal__title"></h2>
        <div id="hfs-legal-body" class="hfs-legal__body"></div>
        <p class="hfs-legal__foot">
            <a id="hfs-legal-full" href="#" target="_blank" rel="noopener">Tam sayfada aç</a>
        </p>
    </div>
</div>
<script type="application/json" id="hfs-legal-data"><?= json_encode($legalModalData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
