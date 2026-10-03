<?php
declare(strict_types=1);
error_reporting(E_ALL);

class NativePropertyBase { public static int $count = 1; }
class NativePropertyShadow extends NativePropertyBase { public static int $count = 10; }
class NativePropertyShared extends NativePropertyBase {}
NativePropertyBase::$count += 2;
NativePropertyShadow::$count += 3;
echo json_encode([NativePropertyBase::$count, NativePropertyShadow::$count]) . "\n";
NativePropertyShared::$count += 5;
echo json_encode([NativePropertyBase::$count, NativePropertyShared::$count, NativePropertyShadow::$count]) . "\n";
try {
    NativePropertyBase::$count = 'bad';
    echo "MUST_NOT_RUN\n";
} catch (Throwable $e) {
    echo 'ERR:' . $e::class . "\n";
}
echo NativePropertyBase::$count . "\n";

class NativeInstanceProperty { public int $n; public string $label = 'ready'; }
$one = new NativeInstanceProperty();
$two = new NativeInstanceProperty();
try {
    $value = (new NativeInstanceProperty())->n;
    echo "MUST_NOT_RUN\n";
} catch (Error $e) {
    echo 'ERR:' . $e::class . "\n";
}
$one->n = 5;
$two->n = 20;
$alias = $one;
$alias->n += 2;
echo json_encode([$one->n, $two->n, $one->label]) . "\n";
try {
    $one->n = 'bad';
    echo "MUST_NOT_RUN\n";
} catch (TypeError $e) {
    echo 'ERR:' . $e::class . "\n";
}
echo $one->n . "\n";
try {
    try {
        $one->n = 'bad';
    } catch (Exception $wrong) {
        echo "MUST_NOT_RUN\n";
    }
} catch (Error $e) {
    echo 'OUTER:' . $e::class . "\n";
}
try {
    call_user_func('missing_native_callback');
    echo "MUST_NOT_RUN\n";
} catch (Throwable $e) {
    echo 'CALLBACK:' . $e::class . "\n";
}
echo call_user_func('abs', -8) . "\n";

$path = tempnam(sys_get_temp_dir(), 'jinx-native-catch-return-');
file_put_contents($path, <<<'INC'
<?php
try {
    $untouched = 1;
} catch (Throwable $e) {
    return 20;
}
$nothing = [];
foreach ($nothing as &$unused) {
    return 30;
}
INC);
echo (include $path) . "\n";
unlink($path);
