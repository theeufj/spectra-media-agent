<?php

namespace App\Prompts;

/** Provider constraints supplement, never replace, local validation and review. */
class AdCopyResponseSchema
{
    public static function forRules(?array $rules): array
    {
        $rules ??= [];
        $properties = [];
        foreach (['headline' => 'headlines', 'description' => 'descriptions'] as $singular => $plural) {
            $items = ['type' => 'string'];
            foreach (['min_length' => 'minLength', 'max_length' => 'maxLength'] as $key => $constraint) {
                if (isset($rules[$singular.'_'.$key])) {
                    $items[$constraint] = (int) $rules[$singular.'_'.$key];
                }
            }
            $properties[$plural] = ['type' => 'array', 'items' => $items];
            if (isset($rules[$singular.'_count'])) {
                $properties[$plural]['minItems'] = $properties[$plural]['maxItems'] = (int) $rules[$singular.'_count'];
            }
        }

        return ['type' => 'object', 'properties' => $properties,
            'required' => ['headlines', 'descriptions'], 'additionalProperties' => false];
    }

    public static function rsa(): array
    {
        $schema = self::forRules(['headline_min_length' => 5, 'headline_max_length' => 30,
            'description_min_length' => 10, 'description_max_length' => 90]);
        $schema['properties']['headlines'] += ['minItems' => 3, 'maxItems' => 15];
        $schema['properties']['descriptions'] += ['minItems' => 2, 'maxItems' => 4];

        return $schema;
    }

    public static function rankRepair(): array
    {
        $schema = self::rsa();
        $schema['properties']['callouts'] = ['type' => 'array', 'minItems' => 0, 'maxItems' => 4,
            'items' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 25]];
        $schema['properties']['sitelinks'] = ['type' => 'array', 'minItems' => 0, 'maxItems' => 4,
            'items' => ['type' => 'object', 'properties' => [
                'link_text' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 25],
                'url' => ['type' => 'string', 'minLength' => 1],
                'description1' => ['type' => 'string', 'maxLength' => 35],
                'description2' => ['type' => 'string', 'maxLength' => 35],
            ], 'required' => ['link_text', 'url', 'description1', 'description2'], 'additionalProperties' => false]];
        $schema['properties']['structured_snippet'] = ['anyOf' => [['type' => 'null'], [
            'type' => 'object', 'properties' => [
                'header' => ['type' => 'string', 'minLength' => 1],
                'values' => ['type' => 'array', 'minItems' => 3, 'maxItems' => 10,
                    'items' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 25]],
            ], 'required' => ['header', 'values'], 'additionalProperties' => false,
        ]]];
        $schema['required'] = array_keys($schema['properties']);

        return $schema;
    }
}
