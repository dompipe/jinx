<?php

declare(strict_types=1);

$result = array_change_key_case(['Name' => 'Ada', 'LANG' => 'PHP'], 0);
echo 'array_change_key_case=' . json_encode($result);
return json_encode($result);
