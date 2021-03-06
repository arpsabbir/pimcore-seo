<?php

namespace SeoBundle\MetaData\Integrator;

use Pimcore\Model\DataObject;
use SeoBundle\Helper\ArrayHelper;
use SeoBundle\MetaData\MetaDataProviderInterface;
use SeoBundle\Model\SeoMetaDataInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class SchemaIntegrator implements IntegratorInterface
{
    /**
     * @var array
     */
    protected $configuration;

    /**
     * @var MetaDataProviderInterface
     */
    protected $metaDataProvider;

    /**
     * @param MetaDataProviderInterface $metaDataProvider
     */
    public function __construct(MetaDataProviderInterface $metaDataProvider)
    {
        $this->metaDataProvider = $metaDataProvider;
    }

    /**
     * {@inheritdoc}
     */
    public function getBackendConfiguration($element)
    {
        $useLocalizedFields = $element instanceof DataObject;
        $hasDynamicallyAddedJsonLdData = false;
        $addedJsonLdDataTypes = [];

        foreach (\Pimcore\Tool::getValidLanguages() as $locale) {
            $seoMetaData = null;
            if (method_exists($this->metaDataProvider, 'getSeoMetaDataForBackend')) {
                /** @var SeoMetaDataInterface $seoMetaData */
                $seoMetaData = $this->metaDataProvider->getSeoMetaDataForBackend($element, $locale, ['integrator']);
            }

            if (!$seoMetaData instanceof SeoMetaDataInterface) {
                continue;
            }

            $schemaBlocks = $seoMetaData->getSchema();
            if (is_array($schemaBlocks) && count($schemaBlocks) > 0) {
                $hasDynamicallyAddedJsonLdData = true;
                foreach ($schemaBlocks as $schemaBlock) {
                    if (isset($schemaBlock['@type'])) {
                        if (!isset($addedJsonLdDataTypes[$schemaBlock['@type']])) {
                            $addedJsonLdDataTypes[$schemaBlock['@type']] = 0;
                        }
                        $addedJsonLdDataTypes[$schemaBlock['@type']]++;
                    }
                }
            }
        }

        return [
            'hasDynamicallyAddedJsonLdData'   => $hasDynamicallyAddedJsonLdData,
            'dynamicallyAddedJsonLdDataTypes' => $addedJsonLdDataTypes,
            'useLocalizedFields'              => $useLocalizedFields,
            'hasLivePreview'                  => false,
            'livePreviewTemplates'            => [],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function getPreviewParameter($element, ?string $template, array $data)
    {
        return [];
    }

    /**
     * {@inheritdoc}
     */
    public function validateBeforeBackend(string $elementType, int $elementId, array $data)
    {
        if (!is_array($data) || count($data) === 0) {
            return $data;
        }

        $schemaBlocksConfiguration = [];
        $cleanData = function (array $schemaBlock) {
            $cleanData = json_encode($schemaBlock, JSON_PRETTY_PRINT);

            return sprintf('<script type="application/ld+json">%s</script>', $cleanData);
        };

        foreach ($data as $schemaBlock) {
            if ($schemaBlock['localized'] === false) {
                $schemaBlocksConfiguration[] = ['localized' => false, 'data' => $cleanData($schemaBlock['data'])];
            } elseif ($schemaBlock['localized'] === true) {
                $localizedSchemaBlocksConfiguration = [];
                foreach ($schemaBlock['data'] as $localizedSchemaBlockValue) {
                    $localizedSchemaBlocksConfiguration[] = [
                        'locale' => $localizedSchemaBlockValue['locale'],
                        'value'  => $cleanData($localizedSchemaBlockValue['value'])
                    ];
                }

                $schemaBlocksConfiguration[] = [
                    'localized'  => true,
                    'data'       => $localizedSchemaBlocksConfiguration,
                    'identifier' => $schemaBlock['identifier']
                ];
            }
        }

        return $schemaBlocksConfiguration;
    }

    /**
     * {@inheritdoc}
     */
    public function validateBeforePersist(string $elementType, int $elementId, array $data, $previousData = null)
    {
        if (is_array($data) && count($data) === 0) {
            return null;
        }

        // assert identifier
        foreach ($data as $idx => $row) {
            if (!isset($row['identifier']) || empty($row['identifier'])) {
                $data[$idx]['identifier'] = uniqid('si');
            }
        }

        if ($elementType === 'object') {
            $arrayModifier = new ArrayHelper();
            $data = $arrayModifier->mergeLocaleAwareArrays($data, $previousData, 'identifier', 'data');
        }

        foreach ($data as $index => $schemaBlock) {
            $schemaBlockData = null;
            $localized = false;

            if ($schemaBlock['localized'] === false) {
                $schemaBlockData = $this->validateSchemaBlock($schemaBlock['data']);
            } elseif ($schemaBlock['localized'] === true) {
                $localized = true;
                $localizedSchemaBlockValues = [];
                foreach ($schemaBlock['data'] as $localizedSchemaBlockValue) {
                    if (null !== $localizedSchemaBlockData = $this->validateSchemaBlock($localizedSchemaBlockValue['value'])) {
                        $localizedSchemaBlockValues[] = [
                            'locale' => $localizedSchemaBlockValue['locale'],
                            'value'  => $localizedSchemaBlockData
                        ];
                    }
                }
                if (count($localizedSchemaBlockValues) > 0) {
                    $schemaBlockData = $localizedSchemaBlockValues;
                }
            }

            if ($schemaBlockData === null) {
                unset($data[$index]);

                continue;
            }

            $data[$index] = [
                'localized'  => $localized,
                'data'       => $schemaBlockData,
                'identifier' => $schemaBlock['identifier']
            ];
        }

        $indexedData = array_values($data);

        if (count($indexedData) === 0) {
            return null;
        }

        return $indexedData;
    }

    /**
     * {@inheritdoc}
     */
    public function updateMetaData($element, array $data, ?string $locale, SeoMetaDataInterface $seoMetadata)
    {
        if (count($data) === 0) {
            return;
        }

        foreach ($data as $schemaBlock) {
            if (null !== $value = $this->findLocaleAwareData($schemaBlock, $locale)) {
                $seoMetadata->addSchema($value);
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function setConfiguration(array $configuration)
    {
        $this->configuration = $configuration;
    }

    /**
     * {@inheritdoc}
     */
    public static function configureOptions(OptionsResolver $resolver)
    {
        // no options here.
    }

    /**
     * @param array  $schemaBlock
     * @param string $locale
     *
     * @return array|null
     */
    protected function findLocaleAwareData(array $schemaBlock, $locale)
    {
        if ($schemaBlock['localized'] === false) {
            return $schemaBlock['data'];
        }

        if (empty($locale)) {
            return null;
        }

        if (count($schemaBlock['data']) === 0) {
            return null;
        }

        $index = array_search($locale, array_column($schemaBlock['data'], 'locale'));
        if ($index === false) {
            return null;
        }

        $value = $schemaBlock['data'][$index]['value'];
        if (empty($value) || !is_array($value)) {
            return null;
        }

        return $value;
    }

    /**
     * @param string $data
     *
     * @return array|null
     */
    protected function validateSchemaBlock($data)
    {
        $validatedJsonData = null;

        // already validated
        if (is_array($data)) {
            return $data;
        }

        if (!is_string($data)) {
            return null;
        }

        try {
            $validatedJsonData = $this->validateJsonLd($data);
        } catch (\Throwable $e) {
            return null;
        }

        if ($validatedJsonData === false) {
            return null;
        }

        return $validatedJsonData;
    }

    /**
     * @param string $jsonLdData
     *
     * @return bool|array
     *
     * @throws \Exception
     */
    protected function validateJsonLd(string $jsonLdData)
    {
        $jsonLdData = preg_replace(
            '/[ \t\n]+/',
            ' ',
            preg_replace('/\s*$^\s*/m', ' ', $jsonLdData)
        );

        $dom = new \DOMDocument();
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = false;

        libxml_use_internal_errors(1);
        $dom->loadHTML($jsonLdData);
        $xpath = new \DOMXPath($dom);
        $jsonScripts = $xpath->query('//script[@type="application/ld+json"]');

        // Handle CDATA stuff
        if (isset($jsonScripts->item(1)->nodeValue)) {
            $json = $jsonScripts->item(1)->nodeValue;
        } else {
            $json = $jsonScripts->item(0)->nodeValue;
        }

        $data = json_decode(trim($json), true);

        if ($data === null) {
            return false;
        }

        return $data;
    }
}
