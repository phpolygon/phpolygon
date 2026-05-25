#import "PHPRuntime.h"

#include <sapi/embed/php_embed.h>
#include <main/php.h>
#include <Zend/zend_hash.h>
#include <Zend/zend_string.h>

/* Writable directory for PHP/OPcache temp artifacts, resolved at runtime to
 * the app container's tmp dir. Filled in before php_embed_init() and read by
 * the ini_defaults callback below. */
static char g_ios_tmp_dir[1024];

/*
 * SAPI ini_defaults hook. php_embed.c invokes this as soon as PHP's
 * configuration hash is allocated - earlier than the embed SAPI's
 * HARDCODED_INI string, and earlier than every module's MINIT. Values
 * written here have the LOWEST precedence (an INI file could override them),
 * but crucially HARDCODED_INI does not mention OPcache, so these stick.
 *
 * Why this and not ini_entries: php_embed_init() overwrites
 * php_embed_module.ini_entries with its own HARDCODED_INI, so anything set
 * there is discarded. ini_defaults is the officially documented escape hatch
 * (see the comment block in sapi/embed/php_embed.c).
 *
 * PHP 8.5 has no --disable-opcache configure flag anymore (OPcache is always
 * compiled in; only its JIT is optional), so disabling it at runtime is the
 * only way to stop its MINIT from creating a lock file under /tmp - which the
 * iOS sandbox forbids ("Operation not permitted"). We both turn OPcache off
 * and point its lock-file dir at the writable app tmp as belt-and-suspenders.
 */
static void php_ios_ini_defaults(HashTable *configuration_hash)
{
    zval tmp;

#define IOS_INI_DEFAULT(name, value) \
    ZVAL_NEW_STR(&tmp, zend_string_init((value), strlen(value), 1)); \
    zend_hash_str_update(configuration_hash, (name), sizeof(name) - 1, &tmp);

    IOS_INI_DEFAULT("opcache.enable", "0");
    IOS_INI_DEFAULT("opcache.enable_cli", "0");
    if (g_ios_tmp_dir[0] != '\0') {
        IOS_INI_DEFAULT("opcache.lockfile_path", g_ios_tmp_dir);
        IOS_INI_DEFAULT("sys_temp_dir", g_ios_tmp_dir);
    }

#undef IOS_INI_DEFAULT
}

@implementation PHPRuntime

+ (NSString *)runBundledScript:(NSString *)scriptName {
    /* scriptName is a bundle-relative path like "CodeTycoon/ios_main".
     * Resolve it against the bundle root. */
    NSString *path = [[NSBundle mainBundle] pathForResource:scriptName ofType:@"php"];
    if (!path) {
        return [NSString stringWithFormat:@"script not found in bundle: %@.php", scriptName];
    }

    /* iOS sandbox: /tmp is not writable. Point TMPDIR and (via ini_defaults
     * below) PHP's temp/lock paths at the app container's tmp dir, which is
     * writable. Must be set before php_embed_init(). */
    NSString *tmpDir = NSTemporaryDirectory();
    setenv("TMPDIR", [tmpDir fileSystemRepresentation], 1);
    strncpy(g_ios_tmp_dir, [tmpDir fileSystemRepresentation], sizeof(g_ios_tmp_dir) - 1);
    g_ios_tmp_dir[sizeof(g_ios_tmp_dir) - 1] = '\0';

    /* Expose the writable Documents dir to PHP. ios_main.php redirects the
     * game's cache/store/saves here since the bundle is read-only. */
    NSString *docs = [NSSearchPathForDirectoriesInDomains(
        NSDocumentDirectory, NSUserDomainMask, YES) firstObject];
    if (docs) {
        setenv("PHPOLYGON_IOS_DOCS", [docs fileSystemRepresentation], 1);
    }

    /* Working directory = the loaded script's directory, so the game's
     * relative requires (bootstrap.php, src/, vendor/) resolve. */
    NSString *scriptDir = [path stringByDeletingLastPathComponent];
    chdir([scriptDir fileSystemRepresentation]);

    /* Install the ini_defaults hook BEFORE php_embed_init runs (via the
     * START_BLOCK macro). This is how OPcache gets disabled on iOS - PHP 8.5
     * always compiles OPcache in, and its MINIT would otherwise create a
     * lock file under /tmp and crash in the sandbox. */
    php_embed_module.ini_defaults = php_ios_ini_defaults;

    /* php_embed_module is a SAPI module pre-baked into libphp.a's embed
     * SAPI. PHP_EMBED_START_BLOCK / END_BLOCK wrap it with the standard
     * argc/argv handshake and zend_first_try/zend_end_try error fence. */
    int argc = 1;
    const char *argv0 = "iosapp";
    char *argv[] = { (char *)argv0, NULL };

    __block NSMutableString *output = [NSMutableString string];

    PHP_EMBED_START_BLOCK(argc, argv) {
        zend_file_handle file_handle;
        zend_stream_init_filename(&file_handle, [path fileSystemRepresentation]);

        if (php_execute_script(&file_handle) == FAILURE) {
            [output appendString:@"php_execute_script: FAILURE"];
        } else {
            [output appendString:@"php_execute_script: OK"];
        }

        zend_destroy_file_handle(&file_handle);
    } PHP_EMBED_END_BLOCK();

    return output;
}

@end
