<?php

namespace DvTest;

use Exception;

/**
 * Вольный перевод C-шной версии H3 индекса на PHP
 * @see https://github.com/uber/h3
 */
class H3Index {

    // Максимальное разрешение (выше разрядность не дает, да и незачем)
    const MAX_RESOLUTION = 15;

    // Базовое значение H3 - 0-я ячейка 0-го разрешения
    const INITIAL_VALUE = 35184372088831;

    // 64-х битное целое с маской в нужном месте
    // Разрешение индекса
    const RESOLUTION_MASK   = 0b0000000011110000000000000000000000000000000000000000000000000000;
    // Режим работы индекса (используется только один)
    const MODE_MASK         = 0b0111100000000000000000000000000000000000000000000000000000000000;
    // Место базовой ячейки
    const BASE_CELL_MASK    = 0b0000000000001111111000000000000000000000000000000000000000000000;
    // Размер цифры работает в паре с (self::PER_DIGIT_OFFSET)
    const DIGIT_MASK        = 0b0000000000000000000000000000000000000000000000000000000000000111;
    // Теоретически может быть другая маска
    const HEXAGON_MODE_MASK = 0b0000100000000000000000000000000000000000000000000000000000000000;

    // Маска разрешения начинается в 52-м бите
    const RESOLUTION_OFFSET = 52;
    // Маска базовой ячейки начинается с 45
    const BASE_CELL_OFFSET  = 45;
    // Каждая цифра занимает 3 бита
    const PER_DIGIT_OFFSET  = 3;

    /** @var int */
    private $h3;

    /** @var int */
    private $resolution;

    private function __construct(int $resolution) {
        $this->h3         = (static::INITIAL_VALUE & ~static::MODE_MASK) | static::HEXAGON_MODE_MASK;
        $this->h3         = ($this->h3 & ~static::RESOLUTION_MASK) | ($resolution << static::RESOLUTION_OFFSET);
        $this->resolution = $resolution;
    }

    public static function createFromString(string $h3AsString): ?H3Index {
        $h3AsInteger = hexdec($h3AsString);
        $resolution  = ($h3AsInteger & static::RESOLUTION_MASK) >> static::RESOLUTION_OFFSET;
        if ($resolution > static::MAX_RESOLUTION || $resolution < 0) {
            return null;
        }
        $h3Index = new self($resolution);
        $h3Index->h3 = $h3AsInteger;
        return $h3Index;
    }

    /**
     * Строит H3Index по заданным координатам
     */
    public static function createFromCoordinates(float $latitude, float $longitude, int $resolution): H3Index {
        // Координаты нам нужны в радианах
        $radianCoordinates = new GeoCoordinates($latitude, $longitude, true);

        // Из географических координат в сферические
        $vectorInRadialUnits = new Vector3d(
            cos($radianCoordinates->longitude) * cos($radianCoordinates->latitude),
            sin($radianCoordinates->longitude) * cos($radianCoordinates->latitude),
            sin($radianCoordinates->latitude)
        );

        // Находим грань икосаэдра, на которой расположена искомая точка
        $face = 0;
        $minSquareDistance = $vectorInRadialUnits->getSquareDistanceToFaceCenter($face);
        for ($i = 1; $i < FaceDescriptions::ICOSAHEDRON_FACE_COUNT; $i++) {
            $squreDistance = $vectorInRadialUnits->getSquareDistanceToFaceCenter($i);
            if ($squreDistance < $minSquareDistance) {
                $face              = $i;
                $minSquareDistance = $squreDistance;
            }
        }

        // Расстояние от центра икосаэдра до искомой точки в угловых величинах
        $centeredAngle = acos(1 - $minSquareDistance / 2);
        if ($centeredAngle < H3Utils::EPSILON) {
            // Попали в центр грани икосаэдра
            $hexagonCoordinates = new HexagonCoordinates(0, 0, 0);
        } else {
            $azimuthFromFaceCenterToPoint = H3Utils::normalizeRadians(FaceDescriptions::getFaceCenterGeoCoordinates($face)->getAzimuthToPoint($radianCoordinates));
            // Угол между осью - началом отсчёта и отрезком, соединяющим центр грани и искомую точку
            $theta = H3Utils::normalizeRadians(FaceDescriptions::getBasisAxesForFace($face)->x - $azimuthFromFaceCenterToPoint);
            if (H3Utils::isResolutionClassIII($resolution)) {
                // Поворот на (asin(sqrt(3.0 / 28.0))) - угол между осями второго и третьего класса сеток
                $theta = H3Utils::normalizeRadians($theta - asin(sqrt(3.0 / 28.0)));
            }
            // Расстояние от центра грани до точки
            $radius = tan($centeredAngle) * pow(sqrt(7), $resolution) / H3Utils::RES0_U_GNOMONIC;

            // И собственно координаты
            $hexagonCoordinates = HexagonCoordinates::createFrom2dCoordinates($radius * cos($theta), $radius * sin($theta));
        }

        // Строим собственно Н3 индекс
        $h3Index = new H3Index($resolution);

        // Для начального разрешения все немного проще
        if ($resolution == 0) {
            if ($hexagonCoordinates->isIncorrect()) {
                throw new Exception('Out of range input');
            }
            $h3Index->setBaseCell(BaseCell::getBaseCellId($face, $hexagonCoordinates));
            return $h3Index;
        }

        // Выставляем последовательно все значения векторов ijk для каждого разрешения слева направо
        // ClassII и ClassIII сдвинуты на примерно 19 градусов, так что вертим в разные стороны
        for ($r = $resolution - 1; $r >= 0; $r--) {
            $lastIJK = clone $hexagonCoordinates;
            if (H3Utils::isResolutionClassIII($r + 1)) {
                $hexagonCoordinates->ap7ParentCounterClockwise();
                $lastCenter = $hexagonCoordinates->ap7ChildCounterClockwise();
            } else {
                $hexagonCoordinates->ap7ParentClockwise();
                $lastCenter = $hexagonCoordinates->ap7ChildClockwise();
            }
            $lastIJK->sub($lastCenter);
            $h3Index->setIndexDigit($r + 1, $lastIJK->toDigit());
        }

        // В результате манипуляций вектор должен остаться корректным, иначе что-то пошло не так
        if ($hexagonCoordinates->isIncorrect()) {
            throw new Exception('Out of range input');
        }

        // Базовая ячейка
        $h3Index->setBaseCell(BaseCell::getBaseCellId($face, $hexagonCoordinates));
        // А вот эта магия с разным количеством поворотов
        $rotationsCount = BaseCell::getBaseCellRotationCount($face, $hexagonCoordinates);
        $baseCellData   = BaseCell::getBaseCell($face, $hexagonCoordinates);
        if ($baseCellData->isPentagon) {
            if ($h3Index->isFirstStepBottomRight()) {
                if ($baseCellData->cwOffsetPentagon[0] == $face || $baseCellData->cwOffsetPentagon[1] == $face) {
                    $h3Index->rotate60DegreeClockwise();
                } else {
                    $h3Index->rotate60DegreeCounterClockwise();
                }
            }
            for ($i = 0; $i < $rotationsCount; $i++) {
                $h3Index->rotatePentagon60DegreeCounterClockwise();
            }
        } else {
            for ($i = 0; $i < $rotationsCount; $i++) {
                $h3Index->rotate60DegreeCounterClockwise();
            }
        }
        return $h3Index;
    }

    public function getResolution(): int {
        return $this->resolution;
    }

    /**
     * @return int[]
     */
    public static function getAllowableResolutions(): array {
        $allowableResolutions = [];
        for ($i = 1; $i <= static::MAX_RESOLUTION; $i++) {
            $allowableResolutions[$i] = $i;
        }
        return $allowableResolutions;
    }

    /**
     * Возвращает представление H3 индекса как шестнадцетиричное число в виде строки
     */
    public function getAsHex(): string {
        return dechex($this->h3);
    }

    /**
     * Возвращает представление H3 индекса как целое.
     * Неудобочитаемо в человеческом виде, поэтому использовать стоит только в нагруженных местах
     */
    public function getAsInt(): int {
        return $this->h3;
    }

    /**
     * Возвращает центр гексагона в привычных координатах
     */
    public function getCenter(): GeoCoordinates {
        $baseCell = BaseCell::getBaseCellById($this->getBaseCell());
        // В некоторых базовых ячейках бывают пятиугольники и в них иногда нужен поворот
        if ($baseCell->isPentagon && $this->getLeadingNonZeroDigit() == 5) {
            $this->rotate60DegreeClockwise();
        }
        $faceId             = $baseCell->face;
        $hexagonCoordinates = $baseCell->hexagonCoordinates;

        // Последовательно сдвигаемся по координатам в заданных направлениях
        for ($resolution = 1; $resolution <= $this->resolution; $resolution++) {
            if (H3Utils::isResolutionClassIII($resolution)) {
                $hexagonCoordinates = $hexagonCoordinates->ap7ChildCounterClockwise();
            } else {
                $hexagonCoordinates = $hexagonCoordinates->ap7ChildClockwise();
            }
            $hexagonCoordinates->doNeighborByDirectionDigit($this->getIndexDigit($resolution));
        }

        // Для граничных случаев делаем преобразование координат в новую базовую поверхность
        if (!(!$baseCell->isPentagon && ($this->resolution == 0 || $hexagonCoordinates->isZero()))) {
            $faceId = $hexagonCoordinates->adjustOverageClassIIAndGetFaceId($baseCell->face, $this->resolution, $baseCell->isPentagon, $this->getLeadingNonZeroDigit() == 4);
        }
        // В этом месте в $hexagonCoordinates лежат координаты в i-j-k базисе относительно центра поверхности faceId
        return $hexagonCoordinates->convertToGeoCoordinates($faceId, $this->resolution);
    }

    /**
     * Возвращает соседнюю в заданном направлении ячейку или null, если таковой нет
     */
    private function getNeighbor(int $direction): ?H3Index {
        $neighbor = new self($this->resolution);
        $neighbor->h3 = $this->h3;

        $rotationCount = 0;
        $cellId        = $neighbor->getBaseCell();
        $oldBaseCell   = BaseCell::getBaseCellById($cellId);

        $oldLeadingZeroDigit = $neighbor->getLeadingNonZeroDigit();

        // Выставляем значения индекса от текущего разрешения до нулевого
        for ($resolution = $neighbor->getResolution() - 1; $resolution >= -1; $resolution--) {
            $oldDigit = $neighbor->getIndexDigit($resolution + 1);
            if (H3Utils::isResolutionClassIII($resolution + 1)) {
                $neighbor->setIndexDigit($resolution + 1, DirectionDigit::getNewDigitClassII($oldDigit, $direction));
                $nextDirection = DirectionDigit::getNewAdjustmentII($oldDigit, $direction);
            } else {
                $neighbor->setIndexDigit($resolution + 1, DirectionDigit::getNewDigitClassIII($oldDigit, $direction));
                $nextDirection = DirectionDigit::getNewAdjustmentIII($oldDigit, $direction);
            }

            if ($nextDirection == DirectionDigit::CENTER) {
                break;
            }
            $direction = $nextDirection;
        }

        // Если мы после всех итераций с поворотами на каждом разрешении
        // Пришли в центр - значит надо сменить базовую ячейку
        if ($direction == DirectionDigit::CENTER) {
            $newBaseCellId = BaseCell::getNeighbor($oldBaseCell->cellId, $direction);
            if ($newBaseCellId === null) {
                $neighbor->setBaseCell(BaseCell::getNeighbor($oldBaseCell->cellId, DirectionDigit::IK_AXES));
                $rotationCount = BaseCell::getNeighborCounterClockwiseRotations($oldBaseCell->cellId, DirectionDigit::IK_AXES);
                $neighbor->rotate60DegreeCounterClockwise();
            } else {
                $neighbor->setBaseCell($newBaseCellId);
                $rotationCount = BaseCell::getNeighborCounterClockwiseRotations($oldBaseCell->cellId, $direction);
            }
        }

        // Если базовая ячейка получилась пентагоном, нужно немного магии
        $newBaseCell = BaseCell::getBaseCellById($neighbor->getBaseCell());
        if ($newBaseCell->isPentagon) {
            // Определение что надо скорректировать, если первый шаг получился в отсутствующую сторону (из пентагона только пять направлений)
            if ($neighbor->isFirstStepBottomRight()) {
                if ($oldBaseCell->cellId != $newBaseCell->cellId) {
                    if ($newBaseCell->cwOffsetPentagon[0] == $oldBaseCell->face || $newBaseCell->cwOffsetPentagon[1] == $oldBaseCell->face) {
                        $neighbor->rotate60DegreeClockwise();
                    } else {
                        $neighbor->rotate60DegreeCounterClockwise();
                    }
                } else {
                    if ($oldLeadingZeroDigit == DirectionDigit::CENTER) {
                        return null;
                    }
                    if ($oldLeadingZeroDigit == DirectionDigit::JK_AXES) {
                        $neighbor->rotate60DegreeCounterClockwise();
                    } elseif ($oldLeadingZeroDigit == DirectionDigit::IK_AXES) {
                        $neighbor->rotate60DegreeClockwise();
                    } else {
                        return null;
                    }
                }
            }

            for ($i = 0; $i < $rotationCount; $i++) {
                $neighbor->rotatePentagon60DegreeCounterClockwise();
            }
        } else {
            // Вот это вот наиболее частый случай - базовая ячейка не изменилась
            for ($i = 0; $i < $rotationCount; $i++) {
                $neighbor->rotate60DegreeClockwise();
            }
        }

        return $neighbor;
    }

    private function setBaseCell(int $baseCell): void {
        $this->h3 = ($this->h3 & ~static::BASE_CELL_MASK) | ($baseCell << static::BASE_CELL_OFFSET);
    }

    private function getBaseCell() {
        return ($this->h3 & static::BASE_CELL_MASK) >> static::BASE_CELL_OFFSET;
    }

    private function isFirstStepBottomRight(): bool {
        return $this->getLeadingNonZeroDigit() == DirectionDigit::K_AXES;
    }

    private function getLeadingNonZeroDigit(): int {
        for ($r = 1; $r <= $this->resolution; $r++) {
            $indexDigit = $this->getIndexDigit($r);
            if ($indexDigit > 0) {
                return $indexDigit;
            }
        }
        return 0;
    }

    private function rotate60DegreeClockwise(): void {
        for ($r = 1; $r <= $this->resolution; $r++) {
            $this->setIndexDigit($r, DirectionDigit::rotateDigit60DegreeClockwise($this->getIndexDigit($r)));
        }
    }

    private function rotate60DegreeCounterClockwise(): void {
        for ($r = 1; $r <= $this->resolution; $r++) {
            $this->setIndexDigit($r, DirectionDigit::rotateDigit60DegreeCounterClockwise($this->getIndexDigit($r)));
        }
    }

    private function rotatePentagon60DegreeCounterClockwise(): void {
        $foundFirstNonZeroDigit = false;
        for ($r = 1; $r <= $this->resolution; $r++) {
            $this->setIndexDigit($r, DirectionDigit::rotateDigit60DegreeCounterClockwise($this->getIndexDigit($r)));

            // При повороте пентагона нужна коррекция
            if (!$foundFirstNonZeroDigit && $this->getIndexDigit($r) != 0) {
                $foundFirstNonZeroDigit = true;
                if ($this->isFirstStepBottomRight()) {
                    $this->rotate60DegreeCounterClockwise();
                }
            }
        }
    }

    private function setIndexDigit(int $resolution, int $digit) {
        $shiftedBitsCount = (static::MAX_RESOLUTION - $resolution) * static::PER_DIGIT_OFFSET;
        $this->h3 = ($this->h3 & ~(static::DIGIT_MASK << $shiftedBitsCount)) | ($digit << $shiftedBitsCount);
    }

    private function getIndexDigit(int $resolution): int {
        $shiftBitsCount = (static::MAX_RESOLUTION - $resolution) * static::PER_DIGIT_OFFSET;
        return ($this->h3 >> $shiftBitsCount) & static::DIGIT_MASK;
    }
}

