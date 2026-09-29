<?php

namespace Tests\Support;

/**
 * Thrown when the test suite refuses to touch the configured database (TOG-9649).
 *
 * This is an error, never a skip: a skipped suite reads as green, and a suite
 * pointed at the wrong database must be red and loud.
 */
final class TestDatabaseRefusedException extends \RuntimeException {}
