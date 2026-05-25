#import <Foundation/Foundation.h>

/*
 * PHPRuntime bridges UIKit-side ObjC into the embed SAPI of the linked-in
 * libphp.a. One PHP runtime per process - all calls must happen on the
 * main thread because vio (the renderer) is main-thread-only.
 */
@interface PHPRuntime : NSObject

/*
 * Initialise the PHP embed SAPI, execute a bundled .php script, capture
 * stdout into the returned string, then leave the runtime initialised
 * so the PHP frame loop can keep running on subsequent main-loop ticks.
 *
 * Returns the captured stdout (typically empty when vio takes over),
 * or a diagnostic string when the script could not be located or PHP
 * threw a startup error.
 *
 * scriptName is the bundle-resource basename, e.g. "hello" for hello.php.
 */
+ (NSString *)runBundledScript:(NSString *)scriptName;

@end
