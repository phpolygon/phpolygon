#import "ViewController.h"
#import "PHPRuntime.h"

@interface ViewController ()
@property (nonatomic, strong) UILabel *statusLabel;
@property (nonatomic, assign) BOOL phpStarted;
@property (nonatomic, weak) UIView *renderView;
@end

@implementation ViewController

- (void)viewDidLoad {
    [super viewDidLoad];
    /* Transparent so the VioRenderView that vio_ios_setup_context attaches
     * to the window (behind this controller's view via sendSubviewToBack)
     * shows through. */
    self.view.backgroundColor = [UIColor clearColor];

    /* This controller's view is the frontmost, full-screen view, so it is
     * the one UIKit hit-tests first. We KEEP it interactive and forward every
     * touch to the VioRenderView (see touchesBegan: below).
     *
     * The older approach - userInteractionEnabled=NO so touches "fall through"
     * to the render-view sibling behind us - is fragile: on iPad the
     * fall-through did not deliver any touches (the render view never received
     * touchesBegan, mouse stayed at 0,0), while it happened to work on iPhone.
     * Explicit forwarding is device-independent. */
    self.view.userInteractionEnabled = YES;
    self.view.multipleTouchEnabled = YES;

    self.statusLabel = [[UILabel alloc] initWithFrame:CGRectZero];
    self.statusLabel.translatesAutoresizingMaskIntoConstraints = NO;
    self.statusLabel.textColor = [UIColor greenColor];
    self.statusLabel.font = [UIFont monospacedSystemFontOfSize:14.0 weight:UIFontWeightRegular];
    self.statusLabel.numberOfLines = 0;
    self.statusLabel.text = @"booting...";
    [self.view addSubview:self.statusLabel];
    [NSLayoutConstraint activateConstraints:@[
        [self.statusLabel.topAnchor constraintEqualToAnchor:self.view.safeAreaLayoutGuide.topAnchor constant:8],
        [self.statusLabel.leadingAnchor constraintEqualToAnchor:self.view.safeAreaLayoutGuide.leadingAnchor constant:8],
        [self.statusLabel.trailingAnchor constraintEqualToAnchor:self.view.safeAreaLayoutGuide.trailingAnchor constant:-8],
    ]];
}

/* ── Touch forwarding ──────────────────────────────────────────────
 *
 * vio_ios_setup_context attaches the VioRenderView to the window. We find it
 * once (lazily, by class name so we don't need its header) and forward the
 * UIResponder touch callbacks to it. Forwarding the original UITouch objects
 * is correct: the render view's own [touch locationInView:self] recomputes the
 * point in its coordinate space, so coordinates stay accurate. */
- (UIView *)renderViewLookup {
    if (self.renderView) return self.renderView;
    for (UIView *v in self.view.window.subviews) {
        if ([NSStringFromClass([v class]) isEqualToString:@"VioRenderView"]) {
            self.renderView = v;
            return v;
        }
    }
    return nil;
}

- (void)touchesBegan:(NSSet<UITouch *> *)touches withEvent:(UIEvent *)event {
    UIView *rv = [self renderViewLookup];
    if (rv) { [rv touchesBegan:touches withEvent:event]; }
}

- (void)touchesMoved:(NSSet<UITouch *> *)touches withEvent:(UIEvent *)event {
    UIView *rv = [self renderViewLookup];
    if (rv) { [rv touchesMoved:touches withEvent:event]; }
}

- (void)touchesEnded:(NSSet<UITouch *> *)touches withEvent:(UIEvent *)event {
    UIView *rv = [self renderViewLookup];
    if (rv) { [rv touchesEnded:touches withEvent:event]; }
}

- (void)touchesCancelled:(NSSet<UITouch *> *)touches withEvent:(UIEvent *)event {
    UIView *rv = [self renderViewLookup];
    if (rv) { [rv touchesCancelled:touches withEvent:event]; }
}

/* Landscape only (16:9). Adjust per game if a different orientation is needed. */
- (UIInterfaceOrientationMask)supportedInterfaceOrientations {
    return UIInterfaceOrientationMaskLandscape;
}

- (BOOL)prefersStatusBarHidden {
    return YES;
}

- (BOOL)prefersHomeIndicatorAutoHidden {
    return YES;
}

- (void)viewDidAppear:(BOOL)animated {
    [super viewDidAppear:animated];

    if (self.phpStarted) return;
    self.phpStarted = YES;
    self.statusLabel.text = @"booting php...";

    /* Run the PHP game loop on a background queue, NOT the main thread.
     * The game's loop (vio_begin/clear/end ...) runs continuously; if it
     * ran on the main thread it would block CoreAnimation (no frame ever
     * reaches the display) and starve touch delivery. On a background
     * thread the main run loop stays free to composite the CAMetalLayer
     * and feed UITouch events into vio_input. vio_ios_setup_context
     * internally hops to the main thread for its UIKit work. */
    dispatch_async(dispatch_get_global_queue(QOS_CLASS_USER_INTERACTIVE, 0), ^{
        NSString *result = [PHPRuntime runBundledScript:@"App/ios_main"];
        dispatch_async(dispatch_get_main_queue(), ^{
            /* PHP only returns here if the script exits (e.g. should_close).
             * For the smoke/touch test the loop runs ~forever, so this label
             * update is mostly for the error path. */
            self.statusLabel.text = result ?: @"php returned nil";
        });
    });
}

@end
