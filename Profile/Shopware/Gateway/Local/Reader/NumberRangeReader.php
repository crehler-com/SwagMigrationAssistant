<?php declare(strict_types=1);

namespace SwagMigrationAssistant\Profile\Shopware\Gateway\Local\Reader;

use SwagMigrationAssistant\Migration\DataSelection\DefaultEntities;
use SwagMigrationAssistant\Migration\Gateway\Reader\ReaderInterface;
use SwagMigrationAssistant\Migration\MigrationContextInterface;
use SwagMigrationAssistant\Migration\TotalStruct;
use SwagMigrationAssistant\Profile\Shopware\Gateway\Local\ShopwareLocalGateway;
use SwagMigrationAssistant\Profile\Shopware\ShopwareProfileInterface;

class NumberRangeReader extends AbstractReader implements ReaderInterface
{
    public function supports(MigrationContextInterface $migrationContext): bool
    {
        return $migrationContext->getProfile() instanceof ShopwareProfileInterface
            && $migrationContext->getDataSet()::getEntity() === DefaultEntities::NUMBER_RANGE;
    }

    public function supportsTotal(MigrationContextInterface $migrationContext): bool
    {
        return $migrationContext->getProfile() instanceof ShopwareProfileInterface
            && $migrationContext->getGateway()->getName() === ShopwareLocalGateway::GATEWAY_NAME;
    }

    public function read(MigrationContextInterface $migrationContext): array
    {
        $this->setConnection($migrationContext);
        $numberRanges = $this->fetchNumberRanges();
        $prefix = unserialize($this->fetchPrefix(), ['allowed_classes' => false]);

        if (!$prefix) {
            $prefix = '';
        }

        $locale = $this->getDefaultShopLocale();

        foreach ($numberRanges as &$numberRange) {
            $numberRange['_locale'] = str_replace('_', '-', $locale);
            $numberRange['prefix'] = $prefix;
        }

        return $numberRanges;
    }

    public function readTotal(MigrationContextInterface $migrationContext): ?TotalStruct
    {
        $this->setConnection($migrationContext);

        $total = (int) $this->connection->createQueryBuilder()
            ->select('COUNT(*)')
            ->from('s_order_number')
            ->execute()
            ->fetchColumn();

        return new TotalStruct(DefaultEntities::NUMBER_RANGE, $total);
    }

    private function fetchNumberRanges(): array
    {
        return $this->connection->createQueryBuilder()
            ->select('*')
            ->from('s_order_number')
            ->execute()
            ->fetchAll();
    }

    private function fetchPrefix(): string
    {
        return $this->connection->createQueryBuilder()
            ->select('value')
            ->from('s_core_config_elements')
            ->where('name = "backendautoordernumberprefix"')
            ->execute()
            ->fetch(\PDO::FETCH_COLUMN);
    }
}
