<?php
require __DIR__ . '/../../app/bootstrap.php';
require_closer();
flash('error', 'Les bordereaux ont été remplacés par le partage individuel des messages illustrés.');
redirect('/closer/');
