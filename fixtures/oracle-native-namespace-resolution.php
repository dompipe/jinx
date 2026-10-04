<?php
declare(strict_types=1);

namespace NativeScopeA {
    const LABEL = 'a';
    function label(): string { return LABEL; }
    class Box { public static int $count = 2; }
    enum State { case Ready; }
}
namespace NativeScopeB {
    const LABEL = 'b';
    function label(): string { return LABEL; }
    function strlen(string $text): int { return 99; }
    use NativeScopeA\Box as Imported;
    use NativeScopeA\State;
    use function NativeScopeA\label as importedLabel;
    use const NativeScopeA\LABEL as IMPORTED_LABEL;
    function accept(Imported $box): string { return $box::class; }
    Imported::$count = 5;
    echo json_encode([
        label(), importedLabel(), IMPORTED_LABEL,
        namespace\label(), \NativeScopeA\label(),
        strlen('abc'), \strlen('abc'),
        accept(new Imported()), Imported::$count,
        State::Ready->name, State::class,
    ]), "\n";
}
namespace {
    echo json_encode([\NativeScopeA\LABEL, \NativeScopeB\LABEL]), "\n";
}
