<?php

include "impl/DjangoUnitTestEngineImpl.php";
include "impl/JestUnitTestEngineImpl.php";

final class DjangoJestUnitTestEngine extends ArcanistUnitTestEngine {
    public function run() {
        $djangoUnitTestEngine = new DjangoUnitTestEngineImpl($this);
        $jestUnitTestEngine = new JestUnitTestEngineImpl($this);

        $djangoResults = $djangoUnitTestEngine->run();
        $jestResults = $jestUnitTestEngine->run();

        return array_merge($djangoResults, $jestResults);
    }
}
