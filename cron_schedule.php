<?php
chdir(__DIR__);
passthru('php artisan schedule:run 2>&1');
