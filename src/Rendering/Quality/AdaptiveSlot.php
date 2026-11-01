<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Quality;

/** Where a registered {@see AdaptiveStep} sits among the fixed steps of the stack. */
enum AdaptiveSlot
{
    /** Before everything: the most expensive per pixel. */
    case First;
    /** After the volumetric fog, the SSR tiers and parallax. */
    case AfterReflections;
    /** After the render scale. */
    case AfterRenderScale;
    /** After AO and shadow tiers. */
    case AfterShadows;
    /** After anti-aliasing, cloud shadows and bloom. */
    case AfterBloom;
    /** After the vignette and cavity, before the look of the shading itself changes. */
    case BeforeUnlit;
}
