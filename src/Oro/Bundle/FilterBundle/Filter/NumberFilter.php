<?php

namespace Oro\Bundle\FilterBundle\Filter;

use Oro\Bundle\FilterBundle\Datasource\FilterDatasourceAdapterInterface;
use Oro\Bundle\FilterBundle\Form\Type\Filter\NumberFilterType;
use Symfony\Component\Form\Extension\Core\DataTransformer\NumberToLocalizedStringTransformer;

/**
 * The filter by a numeric value.
 */
class NumberFilter extends AbstractFilter implements FilterPrepareDataInterface
{
    /**
     * {@inheritdoc}
     */
    protected $joinOperators = [
        FilterUtility::TYPE_NOT_EMPTY => FilterUtility::TYPE_EMPTY,
        NumberFilterType::TYPE_NOT_EQUAL => NumberFilterType::TYPE_EQUAL,
    ];

    /** @var NumberToLocalizedStringTransformer */
    private $valueTransformer;

    /**
     * {@inheritdoc}
     */
    protected function getFormType()
    {
        return NumberFilterType::class;
    }

    /**
     * {@inheritdoc}
     */
    protected function buildExpr(FilterDatasourceAdapterInterface $ds, $comparisonType, $fieldName, $data)
    {
        $parameterName = $ds->generateParameterName($this->getName());
        if ($this->isValueRequired($comparisonType)) {
            $ds->setParameter($parameterName, $data['value']);
        }

        return $this->buildComparisonExpr(
            $ds,
            $comparisonType,
            $fieldName,
            $parameterName
        );
    }

    /**
     * {@inheritDoc}
     */
    public function prepareData(array $data): array
    {
        if (!isset($data['value']) || '' === $data['value']) {
            $data['value'] = null;
        } else {
            $type = null;
            if (isset($data['type'])) {
                $type = $this->normalizeType($data['type']);
            }
            if (\in_array($type, NumberFilterType::ARRAY_TYPES, true)) {
                $this->assertScalarValue($data['value']);
                $normalizedValues = [];
                $items = explode(NumberFilterType::ARRAY_SEPARATOR, (string)$data['value']);
                foreach ($items as $item) {
                    $normalizedValues[] = $this->normalizeValue(trim($item));
                }
                $data['value'] = $normalizedValues;
            } else {
                $this->assertScalarValue($data['value']);
                $data['value'] = $this->normalizeValue($data['value']);
            }
        }

        return $data;
    }
    /**
     * {@inheritdoc}
     */
    public function parseData($data)
    {
        if (!\is_array($data)) {
            return false;
        }

        $data = parent::parseData($data);

        if (!$this->isValueRequired($data['type'])) {
            return $data;
        }

        return $this->parseValue($data);
    }

    /**
     * @param array $data
     *
     * @return mixed
     */
    protected function parseValue(array $data)
    {
        if (!isset($data['value'])) {
            return false;
        }

        $value = \in_array($data['type'], NumberFilterType::ARRAY_TYPES, true)
            ? $this->parseArrayValue($data['value'])
            : $this->parseNumericValue($data['value']);
        if (false === $value) {
            return false;
        }
        $data['value'] = $value;

        return $data;
    }

    /**
     * @param mixed $value
     *
     * @return mixed
     */
    protected function parseNumericValue($value)
    {
        if (!is_numeric($value)) {
            return false;
        }

        $divisor = $this->getOr(FilterUtility::DIVISOR_KEY);
        if ($divisor) {
            $value *= $divisor;
        }

        return $value;
    }

    /**
     * @param mixed $value
     *
     * @return mixed
     */
    protected function parseArrayValue($value)
    {
        $result = [];
        $items = \is_array($value)
            ? $value
            : explode(NumberFilterType::ARRAY_SEPARATOR, $value);
        foreach ($items as $item) {
            $val = $this->parseNumericValue(trim($item));
            if (false === $val) {
                return false;
            }
            $result[] = $val;
        }

        return $result;
    }

    /**
     * @param mixed $value
     *
     * @return mixed
     */
    protected function normalizeValue($value)
    {
        if (null === $this->valueTransformer) {
            $this->valueTransformer = new NumberToLocalizedStringTransformer();
        }

        return $this->valueTransformer->reverseTransform((string)$value);
    }

    /**
     * @param mixed $value
     */
    protected function assertScalarValue($value): void
    {
        if (!is_scalar($value)) {
            throw new \RuntimeException(sprintf(
                'The value is not valid. Expected a scalar value, "%s" given.',
                \is_object($value) ? \get_class($value) : \gettype($value)
            ));
        }
    }

    /**
     * Build an expression used to filter data
     *
     * @param FilterDatasourceAdapterInterface $ds
     * @param int $comparisonType
     * @param string $fieldName
     * @param string $parameterName
     * @return string
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    protected function buildComparisonExpr(
        FilterDatasourceAdapterInterface $ds,
        $comparisonType,
        $fieldName,
        $parameterName
    ) {
        switch ($comparisonType) {
            case NumberFilterType::TYPE_GREATER_EQUAL:
                return $ds->expr()->gte($fieldName, $parameterName, true);
            case NumberFilterType::TYPE_GREATER_THAN:
                return $ds->expr()->gt($fieldName, $parameterName, true);
            case NumberFilterType::TYPE_LESS_EQUAL:
                return $ds->expr()->lte($fieldName, $parameterName, true);
            case NumberFilterType::TYPE_LESS_THAN:
                return $ds->expr()->lt($fieldName, $parameterName, true);
            case NumberFilterType::TYPE_NOT_EQUAL:
                return $ds->expr()->neq($fieldName, $parameterName, true);
            case FilterUtility::TYPE_EMPTY:
                if ($this->isAggregateField($ds, $fieldName)) {
                    $fieldName = $ds->expr()->coalesce([$fieldName]);
                }

                return $ds->expr()->isNull($fieldName);
            case FilterUtility::TYPE_NOT_EMPTY:
                if ($this->isAggregateField($ds, $fieldName)) {
                    $fieldName = $ds->expr()->coalesce([$fieldName]);
                }

                return $ds->expr()->isNotNull($fieldName);
            case NumberFilterType::TYPE_IN:
                return $ds->expr()->in($fieldName, $parameterName, true);
            case NumberFilterType::TYPE_NOT_IN:
                return $ds->expr()->notIn($fieldName, $parameterName, true);
            default:
                return $ds->expr()->eq($fieldName, $parameterName, true);
        }
    }

    /**
     * @param FilterDatasourceAdapterInterface $ds
     * @param string $fieldName
     *
     * @return bool
     */
    protected function isAggregateField(FilterDatasourceAdapterInterface $ds, $fieldName)
    {
        return (bool)preg_match('/(?<![\w:.])(\w+)\s*\(/im', $ds->getFieldByAlias($fieldName));
    }

    /**
     * {@inheritdoc}
     */
    public function getMetadata()
    {
        $metadata = parent::getMetadata();

        $formView = $this->getForm()->createView();
        $metadata['formatterOptions'] = $formView->vars['formatter_options'];
        $metadata['arraySeparator'] = $formView->vars['array_separator'];
        $metadata['arrayOperators'] = $formView->vars['array_operators'];
        $metadata['dataType'] = $formView->vars['data_type'];

        return $metadata;
    }

    /**
     * @param string $comparisonType
     * @return bool
     */
    protected function isArrayComparison($comparisonType): bool
    {
        return in_array($comparisonType, NumberFilterType::ARRAY_TYPES);
    }

    /**
     * @param array $data
     * @return array
     */
    protected function getArrayValues(array $data)
    {
        $data['value'] = array_filter(
            array_map(
                function ($value) {
                    $value = trim($value);
                    if (!is_numeric($value)) {
                        return null;
                    }

                    return $value;
                },
                explode(NumberFilterType::ARRAY_SEPARATOR, $data['value'])
            ),
            function ($value) {
                return $value !== null;
            }
        );

        return $data;
    }

    /**
     * Apply configured divisor to a numeric raw value. For filtering this means to multiply the filter value.
     *
     * @param mixed $value
     *
     * @return float
     */
    protected function applyDivisor($value)
    {
        if (!is_numeric($value)) {
            return $value;
        }

        if ($divisor = $this->getOr(FilterUtility::DIVISOR_KEY)) {
            $value = $value * $divisor;
        }

        return $value;
    }
}
