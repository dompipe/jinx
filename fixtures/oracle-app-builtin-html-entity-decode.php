<?php

declare(strict_types=1);

$result = html_entity_decode('&lt;b&gt;Jinx &amp; PHP&lt;/b&gt;');
echo 'html_entity_decode=' . $result;
return $result;
