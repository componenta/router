<?php

declare(strict_types=1);

namespace Componenta\Http\Router\Exception;

/** Invalid route expression or PCRE execution failure, not a routing miss. */
final class RoutePatternException extends RouterException {}
