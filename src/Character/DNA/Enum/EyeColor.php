<?php

declare(strict_types=1);

namespace PHPolygon\Character\DNA\Enum;

/**
 * Discrete eye-colour palette used by the character DNA system.
 * Values are hex colour strings spanning brown, hazel, green, blue, grey and violet.
 */
enum EyeColor: string
{
    case DarkBrown = '#2E1808';
    case Brown     = '#4A2A14';
    case Hazel     = '#7E5D2A';
    case Amber     = '#A87838';
    case Green     = '#3F6B3A';
    case GreenBlue = '#4A6E72';
    case BlueLight = '#6FA8C7';
    case BlueDark  = '#2E5670';
    case GreyBlue  = '#7088A0';
    case Grey      = '#838890';
    case Violet    = '#62416E';
    case Jet       = '#1A100A';
}
