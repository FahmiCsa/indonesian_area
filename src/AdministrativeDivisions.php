<?php

declare(strict_types=1);

namespace FahmiCsa\IndonesianArea;

use InvalidArgumentException;
use RuntimeException;

final class AdministrativeDivisions
{
    /**
     * Pembagian provinsi Papua sesuai kode wilayah BPS/Kemendagri terkini.
     *
     * Dataset sumber masih menyimpan pembagian sebelum empat DOB Papua
     * dibentuk. Pemetaan ini menjaga API tetap memakai 38 provinsi tanpa
     * mengubah kode kabupaten/kota yang sudah berlaku.
     *
     * @var array<string, array{id: string, local: string, en: string, slug: string, regency: int, district: int, village: int}>
     */
    private const CURRENT_PAPUA_PROVINCES = [
        '91' => [
            'id' => '91', 'local' => 'Papua Barat', 'en' => 'West Papua', 'slug' => 'west-papua-91',
            'regency' => 7, 'district' => 86, 'village' => 914,
        ],
        '92' => [
            'id' => '92', 'local' => 'Papua Barat Daya', 'en' => 'Southwest Papua', 'slug' => 'southwest-papua-92',
            'regency' => 6, 'district' => 131, 'village' => 818,
        ],
        '94' => [
            'id' => '94', 'local' => 'Papua', 'en' => 'Papua', 'slug' => 'papua-94',
            'regency' => 9, 'district' => 115, 'village' => 963,
        ],
        '95' => [
            'id' => '95', 'local' => 'Papua Selatan', 'en' => 'South Papua', 'slug' => 'south-papua-95',
            'regency' => 4, 'district' => 78, 'village' => 627,
        ],
        '96' => [
            'id' => '96', 'local' => 'Papua Tengah', 'en' => 'Central Papua', 'slug' => 'central-papua-96',
            'regency' => 8, 'district' => 125, 'village' => 988,
        ],
        '97' => [
            'id' => '97', 'local' => 'Papua Pegunungan', 'en' => 'Highland Papua', 'slug' => 'highland-papua-97',
            'regency' => 8, 'district' => 249, 'village' => 2288,
        ],
    ];

    /** @var array<string, string> kode kabupaten/kota => kode provinsi terkini */
    private const CURRENT_PAPUA_REGENCY_PROVINCES = [
        '9101' => '91', '9102' => '91', '9103' => '91', '9104' => '91', '9105' => '91', '9111' => '91', '9112' => '91',
        '9106' => '92', '9107' => '92', '9108' => '92', '9109' => '92', '9110' => '92', '9171' => '92',
        '9403' => '94', '9408' => '94', '9409' => '94', '9419' => '94', '9420' => '94', '9426' => '94', '9427' => '94', '9428' => '94', '9471' => '94',
        '9401' => '95', '9413' => '95', '9414' => '95', '9415' => '95',
        '9404' => '96', '9410' => '96', '9411' => '96', '9412' => '96', '9433' => '96', '9434' => '96', '9435' => '96', '9436' => '96',
        '9402' => '97', '9416' => '97', '9417' => '97', '9418' => '97', '9429' => '97', '9430' => '97', '9431' => '97', '9432' => '97',
    ];

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
        return $this->filterBySearch(
            $this->currentProvinces($this->load('provinces', 'all-province.json')),
            $search
        );
    }

    /**
     * Kabupaten dan kota berada pada level administratif yang sama.
     *
     * @return array<int, array<string, mixed>>
     */
    public function regencies(?string $provinceId = null, ?string $search = null): array
    {
        $items = $this->normalizeCurrentProvinceReferences($this->load('regencies', 'all-regency.json'));

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
        $items = $this->normalizeCurrentProvinceReferences($this->load('districts', 'all-district.json'));

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
        $regencyId = substr($id, 0, 4);
        $provinceId = self::CURRENT_PAPUA_REGENCY_PROVINCES[$regencyId] ?? substr($id, 0, 2);

        return $this->findById($this->villages($provinceId), $id);
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

        $legacyProvinceId = match ($provinceId) {
            '92' => '91',
            '95', '96', '97' => '94',
            default => $provinceId,
        };

        $legacyProvince = $this->findById($this->load('provinces', 'all-province.json'), $legacyProvinceId);
        $slug = $legacyProvince['name']['slug'] ?? null;
        if (!is_string($slug) || $slug === '') {
            throw new RuntimeException("Slug provinsi {$legacyProvinceId} tidak valid.");
        }

        $items = $this->normalizeCurrentProvinceReferences($this->load(
            'villages:' . $legacyProvinceId,
            'village-by-province' . DIRECTORY_SEPARATOR . $slug . '.json'
        ));

        if (isset(self::CURRENT_PAPUA_PROVINCES[$provinceId])) {
            $items = array_values(array_filter(
                $items,
                static fn (array $item): bool => self::hasAncestor($item, 1, $provinceId)
            ));
        }

        return $items;
    }

    /**
     * @param array<int, array<string, mixed>> $provinces
     * @return array<int, array<string, mixed>>
     */
    private function currentProvinces(array $provinces): array
    {
        $items = [];

        foreach ($provinces as $province) {
            $id = (string) ($province['id'] ?? '');
            if (isset(self::CURRENT_PAPUA_PROVINCES[$id])) {
                $items[] = $this->currentProvinceRecord($province, self::CURRENT_PAPUA_PROVINCES[$id]);
                continue;
            }

            $items[] = $province;
        }

        foreach (['92', '95', '96', '97'] as $id) {
            $items[] = $this->currentProvinceRecord([], self::CURRENT_PAPUA_PROVINCES[$id]);
        }

        usort($items, static fn (array $left, array $right): int => strcmp((string) $left['id'], (string) $right['id']));

        return $items;
    }

    /**
     * @param array<string, mixed> $province
     * @param array{id: string, local: string, en: string, slug: string, regency: int, district: int, village: int} $definition
     * @return array<string, mixed>
     */
    private function currentProvinceRecord(array $province, array $definition): array
    {
        $province['id'] = $definition['id'];
        $province['level'] = 1;
        $province['level_name'] = ['local' => 'Provinsi', 'en' => 'Province'];
        $province['name'] = [
            'local' => $definition['local'],
            'en' => $definition['en'],
            'slug' => $definition['slug'],
        ];
        $province['code'] = ['id' => $definition['id'], 'iso' => null];
        $province['parent'] = null;
        $province['ancestors'] = [];
        $province['children_count'] = [
            'regency' => $definition['regency'],
            'district' => $definition['district'],
            'village' => $definition['village'],
        ];
        $province['zip_codes'] ??= [];
        $province['geo'] ??= ['lat' => null, 'lon' => null];
        $province['metadata'] ??= [];
        $province['source'] = [
            'name' => 'Kementerian Dalam Negeri',
            'url' => 'https://kodewilayah.go.id',
        ];

        return $province;
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    private function normalizeCurrentProvinceReferences(array $items): array
    {
        return array_map(function (array $item): array {
            $regencyId = $item['level'] === 2
                ? (string) ($item['id'] ?? '')
                : $this->ancestorId($item, 2);
            $provinceId = self::CURRENT_PAPUA_REGENCY_PROVINCES[$regencyId] ?? null;

            if ($provinceId === null) {
                return $item;
            }

            $definition = self::CURRENT_PAPUA_PROVINCES[$provinceId];
            $reference = [
                'id' => $definition['id'],
                'level' => 1,
                'name' => [
                    'local' => $definition['local'],
                    'en' => $definition['en'],
                    'slug' => $definition['slug'],
                ],
            ];

            if (($item['parent']['level'] ?? null) === 1) {
                $item['parent'] = $reference;
            }

            foreach (($item['ancestors'] ?? []) as $index => $ancestor) {
                if (($ancestor['level'] ?? null) === 1) {
                    $item['ancestors'][$index] = $reference;
                }
            }

            return $item;
        }, $items);
    }

    /** @param array<string, mixed> $item */
    private function ancestorId(array $item, int $level): string
    {
        foreach (($item['ancestors'] ?? []) as $ancestor) {
            if (($ancestor['level'] ?? null) === $level) {
                return (string) ($ancestor['id'] ?? '');
            }
        }

        return '';
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
