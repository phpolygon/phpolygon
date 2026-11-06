<?php

declare(strict_types=1);

namespace PHPolygon\Runtime;

/*
 * Former name of VioMainWindow, kept so games that still name it keep loading.
 * php-vio 2.41 added a global \VioWindow class (further windows of a context); the
 * engine's main-window adapter was renamed to tell the two apart.
 *
 * @deprecated since 0.98.0, use VioMainWindow; removed before 1.0.0.
 */
class_alias(VioMainWindow::class, VioWindow::class);
