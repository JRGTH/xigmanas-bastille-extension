<?php

declare(strict_types=1);

/**
 * What a release job does. The backing value is the one sent by the GUI and passed to the job script.
 */
enum BastilleJobMode: string
{
    case Bootstrap = 'bootstrap';
    case Destroy = 'destroy';
}
