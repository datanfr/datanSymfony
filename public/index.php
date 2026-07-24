<?php

use App\Kernel;
use Symfony\Bundle\FrameworkBundle\HttpCache\HttpCache;

require_once dirname(__DIR__).'/vendor/autoload_runtime.php';

return function (array $context) {
    $kernel = new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);

    // Les pages de scrutins sont massivement relues et ne changent plus une fois
    // le vote passé (voir VoteController::CACHE_TTL). Le reverse proxy intégré
    // les sert alors sans réexécuter le rendu. En production, un cache HTTP en
    // amont (Varnish, CDN) prend naturellement le relais grâce aux mêmes en-têtes.
    if (!$kernel->isDebug()) {
        return new HttpCache($kernel);
    }

    return $kernel;
};
