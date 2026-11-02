<?php

declare(strict_types=1);

namespace FahmiCsa\IndonesianArea;

use InvalidArgumentException;
use RuntimeException;

final class AdministrativeDivisions
{
    private string $dataPath;

    /** @var array<string, array<int, array<string, mixed>>> */
    private array $cache = [];

    public function __construct(?string $dataPath = null)
    {
        $this->dataPath = $dataPath ?? dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data';
    }

    /** @return array<int, array<string, mixed>> */
    public function provinces(?string $search = null): array
    {
        return $this->filterBySearch($this->load('provinces', 'all-province.json'), $search);
    }

    /**
     * Kabupaten dan kota berada pada level administratif yang sama.
     *
     * @return array<int, array<string, mixed>>
     */
    public function regencies(?string $provinceId = null, ?string $search = null): array
    {
        $items = $this->load('regencies', 'all-regency.json');

        if ($provinceId !== null) {
            $items = array_values(array_filter(
                $items,
                static fn (array $item): bool => ($item['parent']['id'] ?? null) === $provinceId
            ));
        }

        return $this->filterBySearch($items, $search);
    }

    /** @return array<int, array<string, mixed>> */
    public function cities(?string $provinceId = null, ?string $search = null): array
    {
        $items = array_values(array_filter(
            $this->regencies($provinceId, $search),
            static fn (array $item): bool => str_starts_with(
                strtolower((string) ($item['name']['local'] ?? '')),
                'kota '
            )
        ));

        return $items;
    }

    /** @return array<int, array<string, mixed>> */
    public function districts(
        ?string $regencyId = null,
        ?string $search = null,
        ?string $provinceId = null
    ): array {
        $items = $this->load('districts', 'all-district.json');

        if ($regencyId !== null) {
            $items = array_values(array_filter(
                $items,
                static fn (array $item): bool => ($item['parent']['id'] ?? null) === $regencyId
            ));
        }

        if ($provinceId !== null) {
            $items = array_values(array_filter(
                $items,
                static fn (array $item): bool => self::hasAncestor($item, 1, $provinceId)
            ));
        }

        return $this->filterBySearch($items, $search);
    }

    /** @return array<int, array<string, mixed>> */
    public function villages(
        ?string $provinceId = null,
        ?string $districtId = null,
        ?string $search = null
    ): array {
        $provinceId ??= $districtId !== null ? substr($districtId, 0, 2) : null;

        if ($provinceId === null || $provinceId === '') {
            throw new InvalidArgumentException('provinceId atau districtId wajib diberikan.');
        }

        $items = $this->loadVillages($provinceId);

        if ($districtId !== null) {
            $items = array_values(array_filter(
                $items,
                static fn (array $item): bool => ($item['parent']['id'] ?? null) === $districtId
            ));
        }

        return $this->filterBySearch($items, $search);
    }

    /** @return array<string, mixed>|null */
    public function findProvince(string $id): ?array
    {
        return $this->findById($this->provinces(), $id);
    }

    /** @return array<string, mixed>|null */
    public function findRegency(string $id): ?array
    {
        return $this->findById($this->regencies(), $id);
    }

    /** @return array<string, mixed>|null */
    public function findDistrict(string $id): ?array
    {
        return $this->findById($this->districts(), $id);
    }

    /** @return array<string, mixed>|null */
    public function findVillage(string $id): ?array
    {
        return $this->findById($this->villages(substr($id, 0, 2)), $id);
    }

    /** @return array<int, array<string, mixed>> */
    public function searchRegencies(string $query, ?string $provinceId = null): array
    {
        return $this->regencies($provinceId, $query);
    }

    /** @return array<int, array<string, mixed>> */
    public function searchCities(string $query, ?string $provinceId = null): array
    {
        return $this->cities($provinceId, $query);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadVillages(string $provinceId): array
    {
        $province = $this->findProvince($provinceId);

        if ($province === null) {
            throw new InvalidArgumentException("Provinsi dengan ID {$provinceId} tidak ditemukan.");
        }

        $slug = $province['name']['slug'] ?? null;
        if (!is_string($slug) || $slug === '') {
            throw new RuntimeException("Slug provinsi {$provinceId} tidak valid.");
        }

        return $this->load(
            'villages:' . $provinceId,
            'village-by-province' . DIRECTORY_SEPARATOR . $slug . '.json'
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function load(string $cacheKey, string $relativeFile): array
    {
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $file = $this->dataPath . DIRECTORY_SEPARATOR . $relativeFile;
        $contents = @file_get_contents($file);

        if ($contents === false) {
            throw new RuntimeException("File data tidak dapat dibaca: {$file}");
        }

        $data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new RuntimeException("Format data tidak valid: {$file}");
        }

        return $this->cache[$cacheKey] = $data;
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    private function filterBySearch(array $items, ?string $search): array
    {
        $search = trim((string) $search);
        if ($search === '') {
            return $items;
        }

        return array_values(array_filter($items, static function (array $item) use ($search): bool {
            $values = [
                $item['id'] ?? '',
                $item['name']['local'] ?? '',
                $item['name']['en'] ?? '',
                $item['name']['slug'] ?? '',
            ];

            foreach ($values as $value) {
                if (stripos((string) $value, $search) !== false) {
                    return true;
                }
            }

            return false;
        }));
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<string, mixed>|null
     */
    private function findById(array $items, string $id): ?array
    {
        foreach ($items as $item) {
            if (($item['id'] ?? null) === $id) {
                return $item;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $item */
    private static function hasAncestor(array $item, int $level, string $id): bool
    {
        foreach (($item['ancestors'] ?? []) as $ancestor) {
            if (($ancestor['level'] ?? null) === $level && ($ancestor['id'] ?? null) === $id) {
                return true;
            }
        }

        return false;
    }
}
