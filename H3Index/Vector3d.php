<?php

namespace DvTest;

class Vector3d {
    /** @var float */
    public $x;

    /** @var float */
    public $y;

    /** @var float */
    public $z;

    public function __construct(float $x, float $y, float $z) {
        $this->x = $x;
        $this->y = $y;
        $this->z = $z;
    }

    public function squareDistance(Vector3d $target): float {
        return ($this->x - $target->x) * ($this->x - $target->x) + ($this->y - $target->y) * ($this->y - $target->y) + ($this->z - $target->z) * ($this->z - $target->z);
    }

    public static function createFromArray(array $array): Vector3d {
        return new self((float) $array[0], (float) $array[1], (float) $array[2]);
    }

    public function getSquareDistanceToFaceCenter(int $face): float {
        return $this->squareDistance(FaceDescriptions::getFaceCenterPoint($face));
    }
}
