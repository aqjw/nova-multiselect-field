<?php

namespace Aqjw\MultiselectField;

use Exception;
use Laravel\Nova\Fields\Field;
use Illuminate\Support\Collection;
use Laravel\Nova\Contracts\RelatableField;
use Laravel\Nova\Http\Requests\NovaRequest;
use Aqjw\MultiselectField\Traits\MultiselectBelongsToSupport;

class Multiselect extends Field implements RelatableField
{
    use MultiselectBelongsToSupport;

    public $component = 'multiselect-field';

    protected $pageResponseResolveCallback;
    protected $saveAsJSON = false;

    /**
     * Sets the options available for select.
     *
     * @param  array|callable
     * @return \Aqjw\MultiselectField\Multiselect
     **/
    public function options($options = [])
    {
        if (is_callable($options)) $options = call_user_func($options);
        $options = collect($options ?: []);

        $isOptionGroup = $options->contains(function ($label, $value) {
            return is_array($label);
        });

        if ($isOptionGroup) {
            $_options = $options
                ->map(function ($value, $key) {
                    return collect($value + ['value' => $key]);
                })
                ->groupBy('group')
                ->map(function ($value, $key) {
                    return ['label' => $key, 'values' => $value->map->only(['label', 'value'])->toArray()];
                })
                ->values()
                ->toArray();

            return $this->withMeta(['options' => $_options]);
        }


        return $this->withMeta([
            'options' => $options->map(function ($label, $value) {
                return ['label' => $label, 'value' => $value];
            })->values()->all(),
        ]);
    }

    public function api($path, $resourceClass)
    {
        if (empty($resourceClass)) throw new Exception('Multiselect requires resourceClass, none provided.');
        if (empty($path)) throw new Exception('Multiselect requires apiUrl, none provided.');

        $this->resolveUsing(function ($value) use ($resourceClass) {
            $this->options([]);
            $value = array_values((array)$value);

            if (empty($value)) return $value;

            // Handle translatable/collection where values are an array of arrays
            if (is_array($value) && is_array($value[0] ?? null)) {
                $value = collect($value)->flatten(1)->toArray();
            }

            try {
                $modelObj = $resourceClass::newModel();
                $models = $modelObj::whereIn($modelObj->getKeyName(), $value)->get();

                $this->setOptionsFromModels($models, $resourceClass);
            } catch (Exception $e) {
            }

            return $value;
        });

        return $this->withMeta(['apiUrl' => $path, 'labelKey' => $resourceClass::$title]);
    }

    public function asyncResource($resourceClass)
    {
        $apiUrl = "/nova-api/{$resourceClass::uriKey()}";
        return $this->api($apiUrl, $resourceClass);
    }

    protected function resolveAttribute($resource, $attribute)
    {
        $singleSelect = $this->meta['singleSelect'] ?? false;
        $value = data_get($resource, str_replace('->', '.', $attribute));
        $saveAsJson = $this->shouldSaveAsJson($resource, $attribute);

        if ($value instanceof Collection) return $value;
        if ($saveAsJson || $singleSelect) return $value;
        return is_array($value) || is_object($value) ? (array) $value : json_decode($value);
    }

    protected function fillAttributeFromRequest(NovaRequest $request, $requestAttribute, $model, $attribute)
    {
        $singleSelect = $this->meta['singleSelect'] ?? false;
        $value = $request->input($requestAttribute) ?: null;
        $saveAsJson = $this->shouldSaveAsJson($model, $attribute);

        if ($singleSelect) {
            $model->{$attribute} = $value;
        } else {
            $value = is_null($value) ? ($this->nullable ? $value : $value = []) : $value;
            $model->{$attribute} = ($saveAsJson || is_null($value)) ? $value : json_encode($value);
        }
    }

    private function shouldSaveAsJson($model, $attribute)
    {
        if (!is_array($model) && method_exists($model, 'getCasts')) {
            $casts = $model->getCasts();
            $isCastedToArray = ($casts[$attribute] ?? null) === 'array';
            return $this->saveAsJSON || $isCastedToArray;
        }
        return false;
    }

    /**
     * Allows the field to save an actual JSON array to a SQL JSON column.
     *
     * @param bool $saveAsJSON
     * @return \Aqjw\MultiselectField\Multiselect
     **/
    public function saveAsJSON($saveAsJSON = true)
    {
        $this->saveAsJSON = $saveAsJSON;
        return $this;
    }

    /**
     * Sets the max number of options the user can select.
     *
     * @param int $max
     * @return \Aqjw\MultiselectField\Multiselect
     **/
    public function max($max)
    {
        return $this->withMeta(['max' => $max]);
    }

    /**
     * Sets the placeholder value displayed on the field.
     *
     * @param string $placeholder
     * @return \Aqjw\MultiselectField\Multiselect
     **/
    public function placeholder($placeholder)
    {
        return $this->withMeta(['placeholder' => $placeholder]);
    }

    /**
     * Sets the maximum number of options displayed at once.
     *
     * @param int $optionsLimit
     * @return \Aqjw\MultiselectField\Multiselect
     **/
    public function optionsLimit($optionsLimit)
    {
        return $this->withMeta(['optionsLimit' => $optionsLimit]);
    }

    /**
     * Enables or disables reordering of the field values.
     *
     * @param bool $reorderable
     * @return \Aqjw\MultiselectField\Multiselect
     **/
    public function reorderable($reorderable = true)
    {
        return $this->withMeta(['reorderable' => $reorderable]);
    }

    /**
     * Enables the field to be used as a single select.
     *
     * This forces the value saved to be a single value and not an array.
     *
     * @param bool $singleSelect
     * @return \Aqjw\MultiselectField\Multiselect
     **/
    public function singleSelect($singleSelect = true)
    {
        return $this->withMeta(['singleSelect' => $singleSelect]);
    }

    public function taggable($taggable = true)
    {
        return $this->withMeta(['taggable' => $taggable]);
    }

    /**
     * Enables vue-multiselect's group-select feature which allows the
     * user to select the whole group at once.
     *
     * @param bool $groupSelect
     * @return \Aqjw\MultiselectField\Multiselect
     **/
    public function groupSelect($groupSelect = true)
    {
        return $this->withMeta(['groupSelect' => $groupSelect]);
    }

    /**
     * Enable other-field dependency.
     *
     * @param string $otherFieldName
     * @return \Aqjw\MultiselectField\Multiselect
     **/
    public function optionsDependOn($otherFieldName, $options)
    {
        return $this->withMeta([
            'optionsDependOn' => $otherFieldName,
            'optionsDependOnOptions' => $options,
        ]);
    }

    /**
     * Enable other-field dependency that is not inside the same Flexible content.
     *
     * @param string $otherFieldName
     * @return \Aqjw\MultiselectField\Multiselect
     **/
    public function optionsDependOnOutsideFlexible($otherFieldName, $options)
    {
        return $this->withMeta([
            'optionsDependOn' => $otherFieldName,
            'optionsDependOnOptions' => $options,
            'optionsDependOnOutsideFlexible' => true,
        ]);
    }

    /**
     * Set max selectable value count as a keyed array of numbers.
     *
     * @param array $maxOptions
     * @return \Aqjw\MultiselectField\Multiselect
     **/
    public function optionsDependOnMax(array $maxOptions)
    {
        return $this->withMeta(['optionsDependOnMax' => $maxOptions]);
    }

    /**
     * Sets the limit value for the field.
     *
     * @param string $limit
     * @return \Aqjw\MultiselectField\Multiselect
     **/
    public function limit($limit)
    {
        return $this->withMeta(['limit' => $limit]);
    }

    /**
     * Sets group name for selects that need to have their values distinct.
     *
     * @param string $group
     * @return \Aqjw\MultiselectField\Multiselect
     **/
    public function distinct($group = "")
    {
        if (empty($group)) $group = $this->attribute;
        return $this->withMeta(['distinct' => $group]);
    }

    public function resolveResponseValue($value, $templateModel)
    {
        $parsedValue = isset($value) ? ($this->saveAsJSON ? $value : json_decode($value)) : null;
        return is_callable($this->pageResponseResolveCallback)
            ? call_user_func($this->pageResponseResolveCallback, $parsedValue, $templateModel)
            : $parsedValue;
    }

    public function resolveForPageResponseUsing(callable $resolveCallback)
    {
        $this->pageResponseResolveCallback = $resolveCallback;
        return $this;
    }

    public function clearOnSelect($clearOnSelect = true)
    {
        return $this->withMeta(['clearOnSelect' => $clearOnSelect]);
    }

    /**
     * Set the options from a collection of models.
     *
     * @param  \Illuminate\Support\Collection  $models
     * @param  string  $resourceClass
     * @return void
     */
    public function setOptionsFromModels(Collection $models, $resourceClass)
    {
        $options = $models->mapInto($resourceClass)->mapWithKeys(function ($associatedResource) {
            return [$associatedResource->getKey() => $associatedResource->title()];
        });
        $this->options($options);
    }

    /**
     * Sets delimiter for joining values on index
     *
     * @param  string $delimiter
     * @return \Aqjw\MultiselectField\Multiselect
     */
    public function indexDelimiter(string $delimiter)
    {
        return $this->withMeta(['indexDelimiter' => $delimiter]);
    }

    /**
     * Sets amount of characters that can be shown on index at once
     *
     * @param  int $limit
     * @return \Aqjw\MultiselectField\Multiselect
     */
    public function indexCharDisplayLimit(int $limit)
    {
        return $this->withMeta(['indexCharDisplayLimit' => $limit]);
    }

    /**
     * Sets amount of values that can be shown on index at once
     *
     * @param  int $limit
     * @return \Aqjw\MultiselectField\Multiselect
     */
    public function indexValueDisplayLimit(int $limit)
    {
        return $this->withMeta(['indexValueDisplayLimit' => $limit]);
    }

    /**
     * Display the field as raw HTML using Vue.
     *
     * @return $this
     */
    public function asHtml()
    {
        return $this->withMeta(['asHtml' => true]);
    }
}
