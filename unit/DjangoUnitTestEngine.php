<?php

include "impl/DjangoUnitTestEngineImpl.php";

final class DjangoUnitTestEngine extends ArcanistUnitTestEngine {
    public function run() {
        $djangoUnitTestEngine = new DjangoUnitTestEngineImpl($this);

        return $djangoUnitTestEngine->run();
    }
}
