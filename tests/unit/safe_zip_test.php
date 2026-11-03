<?php
/**
 * Tests for Safe_zip: archive validation and extraction.
 */

require_once app_file('libraries/Safe_zip.php');

function make_zip(array $entries): string
{
    $path = tempnam(sys_get_temp_dir(), 'ziptest-') . '.zip';
    $zip = new ZipArchive();
    assert_true($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);
    foreach ($entries as $name => $contents) {
        $zip->addFromString($name, $contents);
    }
    $zip->close();
    return $path;
}

function make_temp_dir(): string
{
    $dir = sys_get_temp_dir() . '/ziptest-' . uniqid('', true);
    mkdir($dir, 0700, true);
    return $dir;
}

function remove_tree(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($dir);
}

test('safe_zip: extracts a benign archive', function () {
    $zip = make_zip(['docs/readme.txt' => 'hello', 'config/app.txt' => 'world']);
    $dest = make_temp_dir();
    assert_true(Safe_zip::extract($zip, $dest));
    assert_same('hello', file_get_contents($dest . '/docs/readme.txt'));
    assert_same('world', file_get_contents($dest . '/config/app.txt'));
    unlink($zip);
    remove_tree($dest);
});

test('safe_zip: rejects path traversal', function () {
    $zip = make_zip(['../evil.txt' => 'pwned']);
    $dest = make_temp_dir();
    assert_false(Safe_zip::extract($zip, $dest));
    assert_false(file_exists(dirname($dest) . '/evil.txt'));
    unlink($zip);
    remove_tree($dest);
});

test('safe_zip: rejects absolute paths', function () {
    $zip = make_zip(['/tmp/evil-absolute.txt' => 'pwned']);
    $dest = make_temp_dir();
    assert_false(Safe_zip::extract($zip, $dest));
    unlink($zip);
    remove_tree($dest);
});

test('safe_zip: rejects a backslash traversal', function () {
    $zip = make_zip(['..\\evil.txt' => 'pwned']);
    $dest = make_temp_dir();
    assert_false(Safe_zip::extract($zip, $dest));
    unlink($zip);
    remove_tree($dest);
});

test('safe_zip: blocks php by default and allows it only when opted in', function () {
    $zip = make_zip(['code/update.php' => '<?php echo 1;']);
    $dest = make_temp_dir();
    assert_false(Safe_zip::extract($zip, $dest));
    assert_true(Safe_zip::extract($zip, $dest, true));
    assert_true(is_file($dest . '/code/update.php'));
    unlink($zip);
    remove_tree($dest);
});

test('safe_zip: blocks .htaccess and .user.ini', function () {
    $zip = make_zip(['.htaccess' => 'php_value auto_prepend_file x']);
    $dest = make_temp_dir();
    assert_false(Safe_zip::extract($zip, $dest));
    unlink($zip);

    $zip = make_zip(['.user.ini' => 'auto_prepend_file=x']);
    assert_false(Safe_zip::extract($zip, $dest));
    unlink($zip);
    remove_tree($dest);
});

test('safe_zip: rejects a non-zip file', function () {
    $path = tempnam(sys_get_temp_dir(), 'notzip-');
    file_put_contents($path, 'this is not a zip file');
    $dest = make_temp_dir();
    assert_false(Safe_zip::extract($path, $dest));
    unlink($path);
    remove_tree($dest);
});

test('safe_zip: rejects a missing archive', function () {
    $dest = make_temp_dir();
    assert_false(Safe_zip::extract('/no/such/archive.zip', $dest));
    remove_tree($dest);
});
