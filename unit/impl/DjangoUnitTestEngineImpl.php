<?php


const DLINEBREAK =
"======================================================================";
const LINEBREAK =
"----------------------------------------------------------------------";


final class DjangoUnitTestEngineImpl
{
    private $unitTestBase;

    public function __construct($unitTestBase)
    {
        $this->unitTestBase = $unitTestBase;
    }

    public function run()
    {
        // run everything relative to project root, so that our paths match up
        // with $this->getPaths()
        chdir(
            join(
                "/",
                [
                    $this->unitTestBase->getWorkingCopy()->getProjectRoot(),
                    // manage_py_dir should be a relative path to current .arcconfig
                    $this->getConfig("unit.engine.django.manage_py_dir", "")
                ]
            )
        );

        // coverage enabled ?
        $this->unitTestBase->setEnableCoverage($this->getConfig("unit.engine.django.coverage", true));

        $managepyPath = join("/", [$this->getConfig("unit.engine.django.manage_py_file", "")]);

        $testResults = $this->runDjangoTestSuite($managepyPath);
        $testLines = $testResults["testLines"];
        $testExitCode = $testResults["testExitCode"];
        $results = $testResults["results"];

        $resultsArray = array();

        // if we have not found any tests in the output, but the exit code
        // wasn't 0, the entire test suite has failed to run, since it ran
        // no tests
        if (count($results) == 0 && $testExitCode != 0) {
            // name the test "Failed to run tests: " followed by the path
            // of the manage.py file
            $failTestName = "Failed to run: " . $managepyPath;
            $result = new ArcanistUnitTestResult();
            $result->setName($failTestName);
            $result->setResult(ArcanistUnitTestResult::RESULT_FAIL);
            // set the UserData to the raw output of the failed test run
            $result->setUserData(join("\n", $testLines));

            // add to final results array
            $resultsArray[$failTestName] = $result;
        } else if ($this->unitTestBase->getEnableCoverage()) {
            // continue with coverage
            $this->processCoverageResults($results);
        }

        $resultsArray = array_merge($resultsArray, $results);


        return $resultsArray;
    }

    public function getConfig($key, $default)
    {
        return $this->unitTestBase->getConfigurationManager()->getConfigFromAnySource(
            $key,
            $default
        );
    }

    // allow users to specify any additional args to put onto the end of
    // "manage.py test"

    private function runDjangoTestSuite($managepyPath)
    {
        if ($this->unitTestBase->getEnableCoverage()) {
            // cleans coverage results from any previous runs
            exec("coverage erase");
            $cmd = "coverage run --source='.'";
        } else {
            $cmd = "python";
        }

        // runs tests with code coverage for specified app names,
        // only giving results on files in pwd (to ignore 3rd party
        // code), verbosity 2 for individual test results, pipe stderr to
        // stdout as the test results are on stderr, adding additional args
        // specified by the .arcconfig
        $appNames = $this->getAppNames();
        $additionalArgs = $this->getAdditionalManageArgs();

        exec("$cmd $managepyPath test -v2 $appNames $additionalArgs 2>&1",
            $testLines, $testExitCode);

        exec("mv .coverage " . $this->unitTestBase->getWorkingCopy()->getProjectRoot());

        $testResults = array();
        $testResults["testLines"] = $testLines;
        $testResults["testExitCode"] = $testExitCode;
        $testResults["results"] = $this->parseTestResults($testLines);

        return $testResults;
    }

    private function getAppNames()
    {
        return $this->getConfig("unit.engine.django.test_apps", "");
    }

    private function getAdditionalManageArgs()
    {
        return $this->getConfig(
            "unit.engine.django.manage_py_args", "");
    }

    private function parseTestResults($testLines)
    {
        // store the ArcanistUnitTestResults for this project
        $results = array();

        // buffer to help with regex finds, as we use multiline patterns
        $strbuf = "";
        foreach ($testLines as $testLine) {
            $strbuf .= $testLine . "\n";

            // pattern for a test run:
            // test_blah blah (some.package.SimpleTest) blahblah ... ok
            while (preg_match("/(test_.*? \(.*?\)).*? \.\.\. (.*)\n/s",
                $strbuf,
                $testStatusMatches,
                PREG_OFFSET_CAPTURE)) {
                $result = new ArcanistUnitTestResult();
                // name of the test
                $testName = $testStatusMatches[1][0];
                // result (e.g. "ok", "FAIL")
                $testResult = $testStatusMatches[2][0];
                $result->setName($testName);
                // set to default empty, this is the details  displayed
                // when there are errors
                $result->setUserData("");

                if ($testResult == "ok") {
                    $result->setResult(
                        ArcanistUnitTestResult::RESULT_PASS);
                } else if ($testResult == "FAIL") {
                    $result->setResult(
                        ArcanistUnitTestResult::RESULT_FAIL);
                } else if ($testResult == "ERROR") {
                    $result->setResult(
                        ArcanistUnitTestResult::RESULT_FAIL);
                } else if (strpos($testResult, "skipped") == 0) {
                    $result->setResult(
                        ArcanistUnitTestResult::RESULT_SKIP);
                    // sets the skip reason as the UserData (displayed on
                    // arc unit test results)
                    $result->setUserData(substr($testResult, 8));
                } else {
                    // if we don't recognize the test result, default to
                    // RESULT_UNSOUND
                    $result->setResult(
                        ArcanistUnitTestResult::RESULT_UNSOUND);
                }

                // add to dict of UnitTestResults, keyed on name
                $results[$testName] = $result;

                // flush strbuf up to the end of the regex match
                $end = $testStatusMatches[0][1] +
                    strlen($testStatusMatches[0][0]);
                $strbuf = substr($strbuf, $end);
            }

            // pattern for the error/traceback of a failed test:
            // ===...
            // FAIL: test_blah blah
            // ---...
            // Traceback lines
            // more tracebacklines
            // (empty line, so "\n\n")
            while (preg_match(
                "/" . DLINEBREAK . "\n(FAIL|ERROR): (.*)\n" . LINEBREAK . "\n(.*?)\n\n/s",
                $strbuf,
                $failMatches,
                PREG_OFFSET_CAPTURE)) {
                // name of the test
                $testName = $failMatches[2][0];
                // error/traceback string
                $errorStr = $failMatches[3][0];

                // only set UserData on the ArcanistUnitTestResult if it
                // exists
                if (array_key_exists($testName, $results)) {
                    $results[$testName]->setUserData($errorStr);
                }

                // flush strbuf up to the end of the regex match
                $end = $failMatches[0][1] +
                    strlen($failMatches[0][0]);
                $strbuf = substr($strbuf, $end);
            }
        }

        return $results;
    }

    private function processCoverageResults($results)
    {
        // Get the current working directory (The python project root), so we can use it for the cover search later
        $pythonRoot = getcwd();

        // coverage annotation is relative to the project root, so we need to change directory
        chdir($this->unitTestBase->getWorkingCopy()->getProjectRoot());

        // generate annotated source files to find out which lines have
        // coverage
        // limit files to only those "*.py" files in getPaths()
        $pythonPathsStr = join(",", $this->getPythonPaths());
        exec("coverage annotate --include=$pythonPathsStr");

        // store all the coverage results for this project
        $coverageArray = array();

        // walk through project directory, searching for all ",cover" files
        // that coverage.py left behind
        foreach (
            new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(
                    "."),
                RecursiveIteratorIterator::LEAVES_ONLY,
                RecursiveIteratorIterator::CATCH_GET_CHILD
            ) as $path
        ) {
            // paths are given as "./path/to/file.py,cover", so match the
            // "path/to/file.py" part
            if (!preg_match(":^\./(.*),cover$:", $path, $matches)) {
                continue;
            }

            $srcFilePath = $matches[1];

            $coverageStr = "";

            foreach (file($path) as $coverLine) {
                switch ($coverLine[0]) {
                    case '>':
                        $coverageStr .= 'C';
                        break;
                    case '!':
                        $coverageStr .= 'U';
                        break;
                    case ' ':
                        $coverageStr .= 'N';
                        break;
                    case '-':
                        $coverageStr .= 'X';
                        break;
                    default:
                        break;
                }
            }

            // delete the ,cover file
            unlink($path);

            // only add to coverage report if the path was originally
            // specified by arc
            if (in_array($srcFilePath, $this->unitTestBase->getPaths())) {
                $coverageArray[$srcFilePath] = $coverageStr;
            }
        }

        // have all ArcanistUnitTestResults for this project have coverage
        // data for the whole project
        foreach ($results as $path => $result) {
            $result->setCoverage($coverageArray);
        }
    }

    private function getPythonPaths()
    {
        $pythonPaths = array();
        foreach ($this->unitTestBase->getPaths() as $path) {
            if (preg_match("/\.py$/", $path)) {
                $pythonPaths[] = $path;
            }
        }

        return $pythonPaths;
    }
}
