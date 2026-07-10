<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Ui\Component\Listing\Column;

use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

class CredentialActions extends Column
{
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        private readonly UrlInterface $urlBuilder,
        array $components = [],
        array $data = []
    ) {
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    public function prepareDataSource(array $dataSource): array
    {
        if (!isset($dataSource['data']['items'])) {
            return $dataSource;
        }

        foreach ($dataSource['data']['items'] as &$item) {
            if (!isset($item['entity_id'])) {
                continue;
            }
            $name = $this->getData('name');
            $label = $item['friendly_name'] ?: (string) __('Unnamed passkey');
            $item[$name]['revoke'] = [
                'href' => $this->urlBuilder->getUrl(
                    'mageos_passkey/credentials/delete',
                    ['entity_id' => $item['entity_id']]
                ),
                'label' => __('Revoke'),
                'post' => true,
                'confirm' => [
                    'title' => __('Revoke passkey'),
                    'message' => __(
                        'Revoke the passkey "%1" for %2? The customer will no longer be able to sign in with it.',
                        $label,
                        $item['customer_email'] ?? ''
                    ),
                ],
            ];
        }

        return $dataSource;
    }
}
