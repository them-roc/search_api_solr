<?php

/**
 * @file
 * Contains \Drupal\search_api_solr_multilingual\Plugin\search_api\backend\AbstractSearchApiSolrMultilingualBackend.
 */

namespace Drupal\search_api_solr_multilingual\Plugin\search_api\backend;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\search_api_solr_multilingual\SearchApiSolrMultilingualException;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Query\QueryInterface;
use Drupal\search_api\Query\ResultSetInterface;
use Drupal\search_api_solr\Plugin\search_api\backend\SearchApiSolrBackend;
use Drupal\search_api_solr\Utility\Utility as SearchApiSolrUtility;
use Drupal\search_api_solr_multilingual\SolrMultilingualBackendInterface;
use Drupal\search_api_solr_multilingual\Utility\Utility;
use Solarium\Core\Client\Response;
use Solarium\Core\Query\Result\ResultInterface;
use Solarium\QueryType\Select\Query\Query;
use Solarium\QueryType\Select\Result\Result;

/**
 * The name of the language field might be change in future releases of
 * search_api. @see https://www.drupal.org/node/2641392 for details.
 * Therefor we define a constant here that could be easily changed.
 */
define('SEARCH_API_LANGUAGE_FIELD_NAME', 'search_api_language');

/**
 * A abstract base class for all multilingual Solr Search API backends.
 */
abstract class AbstractSearchApiSolrMultilingualBackend extends SearchApiSolrBackend implements SolrMultilingualBackendInterface {

  /**
   * Creates and deploys a missing dynamic Solr field if the server supports it.
   *
   * @param string $solr_field_name
   *   The name of the new dynamic Solr field.
   *
   * @param string $solr_field_type_name
   *   The name of the Solr Field Type to be used for the new dynamic Solr
   *   field.
   */
  abstract protected function createSolrDynamicField($solr_field_name, $solr_field_type_name);

  /**
   * Creates and deploys a missing Solr Field Type if the server supports it.
   *
   * @param string $solr_field_type_name
   *   The name of the Solr Field Type.
   */
  abstract protected function createSolrMultilingualFieldType($solr_field_type_name);

  /**
   * {@inheritdoc}
   */
  public function isManagedSchema() {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    $form['multilingual'] = array(
      '#type' => 'fieldset',
      '#title' => $this->t('Multilingual'),
      '#collapsible' => TRUE,
      '#collapsed' => FALSE,
    );
    $form['multilingual']['sasm_limit_search_page_to_content_language'] = array(
      '#type' => 'checkbox',
      '#title' => $this->t('Limit to current content language.'),
      '#description' => $this->t('Limit all search results to current content language.'),
      '#default_value' => isset($this->configuration['sasm_limit_search_page_to_content_language']) ? $this->configuration['sasm_limit_search_page_to_content_language'] : FALSE,
    );
    $form['multilingual']['sasm_language_unspecific_fallback_on_schema_issues'] = array(
      '#type' => 'checkbox',
      '#title' => $this->t('Use language undefined fall back.'),
      '#description' => $this->t('It might happen that you enable a language within Drupal without updating the Solr field definitions on the Solr server immediately. In this case Drupal will log errors when such a translation gets indexed or if the language is used during searches. If you enable this fall back switch, the language will be mapped to "undefined" until the missing language-specific filed become available on the Solr server.'),
      '#default_value' => isset($this->configuration['sasm_language_unspecific_fallback_on_schema_issues']) ? $this->configuration['sasm_language_unspecific_fallback_on_schema_issues'] : TRUE,
    );

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();

    // Since the form is nested into another, we can't simply use #parents for
    // doing this array restructuring magic. (At least not without creating an
    // unnecessary dependency on internal implementation.)
    foreach ($values['multilingual'] as $key => $value) {
      $form_state->setValue($key, $value);
    }

    // Clean-up the form to avoid redundant entries in the stored configuration.
    $form_state->unsetValue('multilingual');

    parent::submitConfigurationForm($form, $form_state);
  }

  /**
   * Adjusts the language filter before converting the query into a Solr query.
   *
   * @param \Drupal\search_api\Query\QueryInterface $query
   *   The \Drupal\search_api\Query\Query object.
   */
  protected function alterSearchApiQuery(QueryInterface $query) {
    // Do not modify 'Server index status' queries.
    // @see https://www.drupal.org/node/2668852
    if ($query->hasTag('server_index_status')) {
      return;
    }

    parent::alterSearchApiQuery($query);

    $language_ids = $query->getLanguages();

    if (empty($language_ids)) {
      // If the query is generated by views and the query isn't limited by any
      // languages we have to search for all languages using their specific
      // fields.
      if (!$query->hasTag('views') && $this->configuration['sasm_limit_search_page_to_content_language']) {
        $query->setLanguages([\Drupal::languageManager()->getCurrentLanguage(LanguageInterface::TYPE_CONTENT)->getId()]);
      }
      else {
        $language_ids = [LanguageInterface::LANGCODE_NOT_SPECIFIED];
        foreach (\Drupal::languageManager()->getLanguages() as $language) {
          $language_ids[] = $language->getId();
        }
        $query->setLanguages($language_ids);
      }
    }
    elseif (1 == count($language_ids)) {
      // @todo At this point we don't know if someone explicitly searches for
      //   language unspecific content otr if he searches for all languages.
      //   Probably we have to apply some logic here or introduce a
      //   configuration option.
      // @see https://www.drupal.org/node/2717591
      switch ($language_ids[0]) {
        case LanguageInterface::LANGCODE_NOT_SPECIFIED:
        case LanguageInterface::LANGCODE_NOT_APPLICABLE:
        case LanguageInterface::LANGCODE_DEFAULT:
          break;
      }
    }
  }

  /**
   * Modify the query before it is sent to solr.
   *
   * Replaces all language unspecific fulltext query fields by language specific
   * ones.
   *
   * @param \Solarium\QueryType\Select\Query\Query $solarium_query
   *   The Solarium select query object.
   * @param \Drupal\search_api\Query\QueryInterface $query
   *   The \Drupal\search_api\Query\Query object representing the executed
   *   search query.
   */
  protected function preQuery(Query $solarium_query, QueryInterface $query) {
    // Do not modify 'Server index status' queries.
    // @see https://www.drupal.org/node/2668852
    if ($query->hasTag('server_index_status')) {
      return;
    }

    parent::preQuery($solarium_query, $query);

    $language_ids = $query->getLanguages();

    if (!empty($language_ids)) {
      $edismax = $solarium_query->getEDisMax();
      $query_fields = $edismax->getQueryFields();
      $index = $query->getIndex();
      $fulltext_fields = $index->getFulltextFields();
      $field_names = $this->getSolrFieldNames($index);

      foreach($fulltext_fields as $fulltext_field) {
        $field_name = $field_names[$fulltext_field];
        $boost = '';
        if (preg_match('@' . $field_name . '(\^[\d.]+)@', $query_fields, $matches)) {
          $boost = $matches[1];
        }

        $language_specific_fields = [];
        foreach ($language_ids as $language_id) {
          $language_specific_field = SearchApiSolrUtility::encodeSolrDynamicFieldName(Utility::getLanguageSpecificSolrDynamicFieldNameForSolrDynamicFieldName($field_name, $language_id));
          if ($this->isPartOfSchema('dynamicFields', Utility::extractLanguageSpecificSolrDynamicFieldDefinition($language_specific_field))) {
            $language_specific_fields[] = $language_specific_field . $boost;
          }
          else {
            $vars = array(
              '%field' => $language_specific_field,
            );
            if ($this->hasLanguageUndefinedFallback()) {
              \Drupal::logger('search_api_solr_multilingual')->warning('Error while searching: language specific field dynamic %field is not defined in the schema.xml, fallback to language unspecific field is enabled.', $vars);
              $language_specific_fields[] = $language_specific_field . $boost;
            }
            else {
              \Drupal::logger('search_api_solr_multilingual')->error('Error while searching: language specific field dynamic %field is not defined in the schema.xml, fallback to language unspecific field is not enabled.', $vars);
            }
          }
        }

        $query_fields = str_replace(
          $field_name . $boost,
          implode(' ', array_unique($language_specific_fields)),
          $query_fields
        );
      }
      $edismax->setQueryFields($query_fields);

      if (empty($this->configuration['retrieve_data'])) {
        // We need the language to be part of the result to modify the result
        // accordingly in extractResults().
        $solarium_query->addField($field_names[SEARCH_API_LANGUAGE_FIELD_NAME]);
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  protected  function getFilterQueries(QueryInterface $query, array $solr_fields, array $index_fields) {
    $condition_group = $query->getConditionGroup();
    $conditions = $condition_group->getConditions();
    if (empty($conditions) || empty($query->getLanguages())) {
      return parent::getFilterQueries($query, $solr_fields, $index_fields);
    }

    $fq = [];
    foreach ($query->getLanguages() as $langcode) {
      $language_specific_condition_group = $query->createConditionGroup();
      $language_specific_condition_group->addCondition(SEARCH_API_LANGUAGE_FIELD_NAME, $langcode);
      $language_specific_condition_group->addConditionGroup($condition_group);
      $nested_fq = $this->createFilterQueries($language_specific_condition_group, $this->getLanguageSpecificSolrFieldNames($langcode, $solr_fields, reset($index_fields)->getIndex()), $index_fields);
      array_walk_recursive($nested_fq, function (&$query, $key) {
        if (strpos($query, '-') !== 0) {
          $query = '+' . $query;
        }
      });
      $fq[] = '(' . implode(' ', $nested_fq) . ')';
    }
    return [implode(' ', $fq)];
  }

  /**
   * Gets a language-specific mapping from Drupal to Solr field names.
   *
   * @param string $langcode
   *   The lanaguage to get the mapping for.
   * @param array $solr_fields
   *   The mapping from Drupal to Solr field names.
   * @param \Drupal\search_api\IndexInterface $index_fields
   *   The fields handled by the curent index.
   *
   * @return array
   *    The language-specific mapping from Drupal to Solr field names.
   */
  protected function getLanguageSpecificSolrFieldNames($lancgcode, array $solr_fields, IndexInterface $index) {
    // @todo Caching.
    foreach ($index->getFulltextFields() as $fulltext_field) {
      $solr_fields[$fulltext_field] = SearchApiSolrUtility::encodeSolrDynamicFieldName(Utility::getLanguageSpecificSolrDynamicFieldNameForSolrDynamicFieldName($solr_fields[$fulltext_field], $lancgcode));
    }
    return $solr_fields;
  }

  /**
   * @inheritdoc
   */
  protected function extractResults(QueryInterface $query, ResultInterface $result) {
    $index = $query->getIndex();
    $single_field_names = $this->getSolrFieldNames($index, TRUE);
    $data = $result->getData();
    $doc_languages = [];

    foreach ($data['response']['docs'] as &$doc) {
      $language_id = $doc_languages[$this->createId($index->id(), $doc['item_id'])] = $doc[$single_field_names[SEARCH_API_LANGUAGE_FIELD_NAME]];
      foreach (array_keys($doc) as $language_specific_field_name) {
        $field_name = Utility::getSolrDynamicFieldNameForLanguageSpecificSolrDynamicFieldName($language_specific_field_name);
        if ($field_name != $language_specific_field_name) {
          if (Utility::getLanguageIdFromLanguageSpecificSolrDynamicFieldName($language_specific_field_name) == $language_id) {
            $doc[$field_name] = $doc[$language_specific_field_name];
            unset($doc[$language_specific_field_name]);
          }
        }
      }
    }

    if (isset($data['highlighting'])) {
      foreach ($data['highlighting'] as $solr_id => &$item) {
        foreach (array_keys($item) as $language_specific_field_name) {
          $field_name = Utility::getSolrDynamicFieldNameForLanguageSpecificSolrDynamicFieldName($language_specific_field_name);
          if ($field_name != $language_specific_field_name) {
            if (Utility::getLanguageIdFromLanguageSpecificSolrDynamicFieldName($language_specific_field_name) == $doc_languages[$solr_id]) {
              $item[$field_name] = $item[$language_specific_field_name];
              $item[$language_specific_field_name];
            }
          }
        }
      }
    }

    $new_response = new Response(json_encode($data), $result->getResponse()->getHeaders());
    $result = new Result(NULL, $result->getQuery(), $new_response);

    return parent::extractResults($query, $result);
  }

  /**
   * {@inheritdoc}
   */
  protected function postQuery(ResultSetInterface $results, QueryInterface $query, $response) {
    parent::postQuery($results, $query, $response);
 }

  /**
   * Replaces language unspecific fulltext fields by language specific ones.
   *
   * @param \Solarium\QueryType\Update\Query\Document\Document[] $documents
   *   An array of \Solarium\QueryType\Update\Query\Document\Document objects
   *   ready to be indexed, generated from $items array.
   * @param \Drupal\search_api\IndexInterface $index
   *   The search index for which items are being indexed.
   * @param array $items
   *   An array of items being indexed.
   *
   * @see hook_search_api_solr_documents_alter()
   */
  protected function alterSolrDocuments(array &$documents, IndexInterface $index, array $items) {
    parent::alterSolrDocuments($documents, $index, $items);

    $fulltext_fields = $index->getFulltextFields();
    $multiple_field_names = $this->getSolrFieldNames($index);
    $single_field_names = $this->getSolrFieldNames($index, TRUE);
    $fulltext_field_names = array_filter(array_flip($multiple_field_names) + array_flip($single_field_names),
      function($value) use ($fulltext_fields) {
        return in_array($value, $fulltext_fields);
      }
    );

    $field_name_map_per_language = [];
    foreach ($documents as $document) {
      $fields = $document->getFields();
      $language_id = $fields[$single_field_names[SEARCH_API_LANGUAGE_FIELD_NAME]];
      foreach ($fields as $monolingual_solr_field_name => $field_value) {
        if (array_key_exists($monolingual_solr_field_name, $fulltext_field_names)) {
          $multilingual_solr_field_name = Utility::getLanguageSpecificSolrDynamicFieldNameForSolrDynamicFieldName($monolingual_solr_field_name, $language_id);
          $field_name_map_per_language[$language_id][$monolingual_solr_field_name] = SearchApiSolrUtility::encodeSolrDynamicFieldName($multilingual_solr_field_name);
        }
      }
    }

    foreach ($field_name_map_per_language as $language_id => $map) {
      $solr_field_type_name = 'text' . '_' . $language_id;
      if (!$this->isPartOfSchema('fieldTypes', $solr_field_type_name) &&
        !$this->createSolrMultilingualFieldType($solr_field_type_name)
      ) {
        if ($this->hasLanguageUndefinedFallback()) {
          $vars = array(
            '%field' => $solr_field_type_name,
          );
          \Drupal::logger('search_api_solr_multilingual')->warning('Error while indexing: language specific field type %field is not defined in the schema.xml, fallback to language unspecific field type is enabled.', $vars);
          unset($field_name_map_per_language[$language_id]);
        }
        else {
          // @todo Check if a non-language-spefific field type could be replaced
          //   by a language-specific one that has been missing before or if a
          //   concrete one has been assigned by the administrator, for example
          //   field type text_de for language de-AT. If the field type is
          //   exchanged, trigger a re-index process.

          throw new SearchApiSolrMultilingualException('Missing field type ' . $solr_field_type_name . ' in schema.');
        }
      }

      foreach ($map as $monolingual_solr_field_name => $multilingual_solr_field_name) {
        // Handle dynamic fields for multilingual tm and ts.
        foreach (['ts', 'tm'] as $prefix) {
          $multilingual_solr_field_name = SearchApiSolrUtility::encodeSolrDynamicFieldName(Utility::getLanguageSpecificSolrDynamicFieldPrefix($prefix, $language_id)) . '*';
          if (!$this->isPartOfSchema('dynamicFields', $multilingual_solr_field_name) &&
            !$this->createSolrDynamicField($multilingual_solr_field_name, $solr_field_type_name)
          ) {
            if ($this->hasLanguageUndefinedFallback()) {
              $vars = array(
                '%field' => $multilingual_solr_field_name,
              );
              \Drupal::logger('search_api_solr_multilingual')->warning('Error while indexing: language specific dynamic field %field is not defined in the schema.xml, fallback to language unspecific field is enabled.', $vars);
              unset($field_name_map_per_language[$language_id][$monolingual_solr_field_name]);
            }
            else {
              throw new SearchApiSolrMultilingualException('Missing dynamic field ' . $multilingual_solr_field_name . ' in schema.');
            }
          }
        }
      }
    }

    foreach ($documents as $document) {
      $fields = $document->getFields();
      foreach ($field_name_map_per_language as $language_id => $map) {
        if (/* @todo CLIR || */ $fields[$single_field_names[SEARCH_API_LANGUAGE_FIELD_NAME]] == $language_id) {
          foreach ($fields as $monolingual_solr_field_name => $value) {
            if (isset($map[$monolingual_solr_field_name])) {
              $document->addField($map[$monolingual_solr_field_name], $value, $document->getFieldBoost($monolingual_solr_field_name));
              // @todo removal should be configurable
              $document->removeField($monolingual_solr_field_name);
            }
          }
        }
      }
    }
  }

  /**
   * Indicates if an 'element' is part of the Solr server's schema.
   *
   * @param string $kind
   *   The kind of the element, for example 'dynamicFields' or 'fieldTypes'.
   *
   * @param string $name
   *   The name of the element.
   *
   * @return bool
   *    True if an element of the given kind and name exists, false otherwise.
   *
   * @throws \Drupal\search_api_solr_multilingual\SearchApiSolrMultilingualException
   */
  protected function isPartOfSchema($kind, $name) {
    static $previous_calls;

    $state_key = 'sasm.' . $this->getServer()->id() . '.schema_parts';
    $state = \Drupal::state();
    $schema_parts = $state->get($state_key);
    // @todo reset that drupal state from time to time

    if (!isset($previous_calls[$kind])) {
      $previous_calls[$kind] = TRUE;

      if (!is_array($schema_parts) || !isset($schema_parts[$kind]) || !in_array($name, $schema_parts[$kind])) {
        $schema_parts[$kind] = [];
        $response = $this->solrHelper->coreRestGet('schema/' . strtolower($kind));
        foreach ($response[$kind] as $row) {
          $schema_parts[$kind][] = $row['name'];
        }
        $state->set($state_key, $schema_parts);
      }
    }

    return in_array($name, $schema_parts[$kind]);
  }

  /**
   * {@inheritdoc}
   */
  public function getSchemaLanguageStatistics() {
    $available = $this->getSolrHelper()->pingCore();
    $stats = [];
    foreach (\Drupal::languageManager()->getLanguages() as $language) {
      $solr_field_type_name = 'text' . '_' . $language->getId();
      $stats[$language->getId()] = $available ? $this->isPartOfSchema('fieldTypes', $solr_field_type_name) : FALSE;
    }
    return $stats;
  }

  /**
   * {@inheritdoc}
   */
  public function hasLanguageUndefinedFallback() {
    return isset($this->configuration['sasm_language_unspecific_fallback_on_schema_issues']) ?
      $this->configuration['sasm_language_unspecific_fallback_on_schema_issues'] : FALSE;
  }

}
