<?php
/**
 * Schema.org Validator Class
 * @version 1.7.1
 */
class MDTJ_Schema_Validator {

    private $data;
    private $results = [];
    private $rules = [
        'Article' => [
            'recommended' => ['headline', 'image', 'author', 'datePublished', 'publisher'],
            'type_checks' => [ 'author' => ['Person', 'Organization'], 'publisher' => ['Organization'], 'image' => ['ImageObject', 'URL'] ]
        ],
        'NewsArticle' => [
            'recommended' => ['headline', 'image', 'author', 'datePublished', 'publisher'],
            'type_checks' => [ 'author' => ['Person', 'Organization'], 'publisher' => ['Organization'], 'image' => ['ImageObject', 'URL'] ]
        ],
        'Product' => [
            'recommended' => ['name', 'image', 'description', 'offers'],
            'type_checks' => [ 'offers' => ['Offer'], 'brand' => ['Brand', 'Organization'], 'aggregateRating' => ['AggregateRating'], 'review' => ['Review'] ]
        ],
        'Offer' => [ // A nested type check
            'recommended' => ['price', 'priceCurrency', 'availability'],
        ],
        'Recipe' => [
            'recommended' => ['name', 'image', 'recipeIngredient', 'recipeInstructions', 'author', 'cookTime', 'prepTime', 'totalTime'],
            'type_checks' => [ 'nutrition' => ['NutritionInformation'], 'author' => ['Person', 'Organization'] ]
        ],
        'LocalBusiness' => [
            'recommended' => ['name', 'address', 'telephone', 'image', 'priceRange', 'openingHoursSpecification'],
            'type_checks' => [ 'address' => ['PostalAddress'] ]
        ],
        'Event' => [
            'recommended' => ['name', 'startDate', 'location'],
            'type_checks' => [ 'location' => ['Place', 'VirtualLocation', 'PostalAddress'], 'performer' => ['Person', 'Organization'], 'organizer' => ['Person', 'Organization'] ]
        ],
        'FAQPage' => [
            'recommended' => ['mainEntity'],
            'type_checks' => [ 'mainEntity' => ['Question'] ]
        ],
        'Question' => [ // A nested type check for FAQPage
             'recommended' => ['name', 'acceptedAnswer'],
             'type_checks' => [ 'acceptedAnswer' => ['Answer'] ]
        ],
        'VideoObject' => [
            'recommended' => ['name', 'description', 'thumbnailUrl', 'uploadDate', 'contentUrl'],
        ]
    ];

    public function __construct($json_ld_data) {
        $this->data = $json_ld_data;
    }

    public function validate() {
        if (isset($this->data['@graph'])) {
            foreach ($this->data['@graph'] as $item) {
                $this->check_item($item);
            }
        } else {
            $this->check_item($this->data);
        }
        return $this->results;
    }

    private function check_item($item) {
        if (empty($item['@type'])) {
            return;
        }

        $type = is_array($item['@type']) ? $item['@type'][0] : $item['@type'];

        if (isset($this->rules[$type])) {
            $this->check_recommended_properties($type, $item);
            $this->check_property_types($type, $item);
        }

        // Recursively check nested items
        foreach ($item as $property => $value) {
            if (is_array($value)) {
                $values_to_check = isset($value[0]) ? $value : [$value];
                foreach ($values_to_check as $sub_item) {
                    if (is_array($sub_item) && !empty($sub_item['@type'])) {
                        $this->check_item($sub_item); // Recursive call
                    }
                }
            }
        }
    }

    private function check_recommended_properties($type, $item) {
        $rule_set = $this->rules[$type]['recommended'] ?? [];
        foreach ($rule_set as $prop) {
            if (empty($item[$prop])) {
				$this->add_result('suggestion', sprintf("The '%s' property is recommended for a '%s' but is missing.", esc_html($prop), esc_html($type)));
            }
        }
        // Special case: check for priceCurrency if price is set
        if ($type === 'Offer' && !empty($item['price']) && empty($item['priceCurrency'])) {
             $this->add_result('warning', "An 'Offer' with a 'price' should also have a 'priceCurrency' (e.g., 'USD').");
        }
    }

    private function check_property_types($type, $item) {
        $rule_set = $this->rules[$type]['type_checks'] ?? [];
        foreach ($rule_set as $prop => $expected_types) {
            if (isset($item[$prop])) {
                $prop_value = $item[$prop];
                $values_to_check = isset($prop_value[0]) ? $prop_value : [$prop_value];
                foreach($values_to_check as $value) {
                    if (is_string($value) && !in_array('URL', $expected_types)) {
						$this->add_result('warning', sprintf("The '%s' property should be a structured object (e.g., a '%s') but it's a plain text string.", esc_html($prop), esc_html($expected_types[0])));
						
                    } elseif (is_array($value) && !empty($value['@type'])) {
                        $actual_type = is_array($value['@type']) ? $value['@type'][0] : $value['@type'];
                        if (!in_array($actual_type, $expected_types)) {
                           $this->add_result('warning', sprintf("The '%s' property has a type of '%s', but one of %s is expected.", esc_html($prop), esc_html($actual_type), esc_html(implode(', ', $expected_types))));
                        }
                    }
                }
            }
        }
    }

    private function add_result($level, $message) {
		$this->results[] = [
			'level' => sanitize_text_field($level), 
			'message' => wp_kses_post($message)
		];
	}
}
