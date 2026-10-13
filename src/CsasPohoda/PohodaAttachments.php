<?php

declare(strict_types=1);

/**
 * This file is part of the CsasPohoda package
 *
 * https://github.com/Spoje-NET/csas-pohoda
 *
 * (c) Spoje.Net IT s.r.o. <https://spojenet.cz>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace SpojeNet\CsasPohoda;

/**
 * Link attachments of Pohoda bank records, created directly in the Pohoda SQL Server database.
 *
 * mServer cannot update bank records, so the link goes to the DOC table (RelAgID = bank agenda,
 * RelID = BV.ID). The bank records are found by the transaction marker which pohoda-abo-importer
 * stores in the internal note (BV.Pozn2) as "#ABO_<document number>_<account number>#".
 *
 * Configured by DB_CONNECTION (default sqlsrv), DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME and DB_PASSWORD.
 */
class PohodaAttachments
{
    private const CHUNK = 50;

    /**
     * @param array<string, string> $env
     */
    public function __construct(private readonly array $env) {}

    /**
     * True when the database connection is configured.
     *
     * @param array<string, string> $env
     */
    public static function isConfigured(array $env): bool
    {
        return ($env['DB_HOST'] ?? '') !== '' && ($env['DB_DATABASE'] ?? '') !== '';
    }

    /**
     * LIKE pattern of the transaction marker (underscores escaped for SQL Server).
     */
    public static function likePattern(string $transactionId): string
    {
        return '%#'.str_replace(['[', '_'], ['[[]', '[_]'], $transactionId).'#%';
    }

    /**
     * IDs of the bank records (BV.ID) imported for the given transactions.
     *
     * @param array<int, string> $transactionIds like ABO_123_000000-1234567890
     *
     * @return array<int, int>
     */
    public function findBankRecordIds(array $transactionIds): array
    {
        $bv = new \SpojeNet\PohodaSQL\BankovniVypis(null, $this->dbOptions());
        $ids = [];

        foreach (array_chunk(array_values(array_unique($transactionIds)), self::CHUNK) as $chunk) {
            $condition = '('.implode(' OR ', array_fill(0, \count($chunk), 'Pozn2 LIKE ?')).')';

            foreach ($bv->listingQuery()->select(['ID'], true)->where($condition, array_map([self::class, 'likePattern'], $chunk))->fetchAll() as $row) {
                $ids[(int) $row['ID']] = (int) $row['ID'];
            }
        }

        return array_values($ids);
    }

    /**
     * Whether the bank record has the link attached already.
     */
    public function hasUrlAttachment(int $bankRecordId, string $url): bool
    {
        $doc = new \SpojeNet\PohodaSQL\DOC(null, $this->dbOptions());

        return $doc->listingQuery()->select(['ID'], true)->where('RelAgID = ? AND RelID = ? AND Url = ?', [\SpojeNet\PohodaSQL\Agenda::BANK, $bankRecordId, $url])->fetchAll() !== [];
    }

    /**
     * Attach the link to the bank record.
     */
    public function addUrlAttachment(int $bankRecordId, string $url, string $name): bool
    {
        $doc = new \SpojeNet\PohodaSQL\DOC(null, $this->dbOptions());
        $doc->setDataValue('RelAgID', \SpojeNet\PohodaSQL\Agenda::BANK);

        return (bool) $doc->urlAttachment($bankRecordId, $url, $name);
    }

    /**
     * @return array<string, mixed>
     */
    private function dbOptions(): array
    {
        return [
            'dbType' => $this->env['DB_CONNECTION'] ?? 'sqlsrv',
            'server' => $this->env['DB_HOST'] ?? '',
            'dbLogin' => $this->env['DB_USERNAME'] ?? '',
            'dbPass' => $this->env['DB_PASSWORD'] ?? '',
            'database' => $this->env['DB_DATABASE'] ?? '',
            'port' => $this->env['DB_PORT'] ?? '1433',
        ];
    }
}
