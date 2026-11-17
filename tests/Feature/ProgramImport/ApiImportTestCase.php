<?php

namespace Tests\Feature\ProgramImport;

/** Runs the import queue inline (sync driver) so the HTTP flow can be followed end to end. */
abstract class ApiImportTestCase extends ImportTestCase
{
    protected bool $fakeQueue = false;
}
