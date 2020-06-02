<?php

/*
 This is a set of hooks and functions that makes Carbon Fields work with WP Multilang.
 Works with complex fields.
 It works only with single value fields (text, rich text, image etc.) and doesn't work with multiple value fields such as gallery.
*/

use Carbon_Fields\Container;
use Carbon_Fields\Field;

if( ! in_array('wp-multilang/wp-multilang.php', apply_filters('active_plugins', get_option('active_plugins'))) ) {
  add_action(
    'admin_notices',
    function() {
      echo '<div class="error"><p>WP Multilang is not activated.</p></div>';
    }
  );
  return;
}

/*
 WP Multilang stores translations inside the field separated by tags, like this:
 [:en]Hello world[:ru]Привет мир[:be]Прывітанне свет[:]
 crb_wpm_extract_translation extracts values in the current language.
*/
function crb_wpm_extract_translation($storage_array)
{
    foreach ($storage_array as $key => $item) {
        $item->value = stripslashes(wpm_translate_string($item->value));
    }
    return $storage_array;
}
add_filter('carbon_fields_datastore_storage_array', 'crb_wpm_extract_translation');


/*
 Extract translation for assocication field title.
*/
function crb_wpm_extract_translation_assosication_title($title)
{
    return wpm_translate_string($title);
}
add_filter('carbon_fields_association_field_title', 'crb_wpm_extract_translation_assosication_title');


/*
 Get the old field value which containes a multilingual string
 and put in the new value in the current language.
*/
function crb_update_ml_value($field)
{
    $hierarchy = $field->get_hierarchy();
    // Only first level fields, as children of complex fields are dealt with in a different way.
    if (empty($hierarchy)) {
        $new_value = $field->get_value();

        // Filter extracting translations has to be removed to get the multilingual string and not the translated one.
        remove_filter('carbon_fields_datastore_storage_array', 'crb_wpm_extract_translation');
        $old_field = clone $field;
        $old_field->load();
        $ml_value = $old_field->get_value();
        add_filter('carbon_fields_datastore_storage_array', 'crb_wpm_extract_translation');

        $updated_ml_value = wpm_set_new_value($ml_value, $new_value);
        $field->set_value($updated_ml_value);
    }
    return $field;
}
add_filter('carbon_fields_before_field_save', 'crb_update_ml_value');


/*
 For a simple field during saving old value can just be retreived and used to add new value to a multilingual string.
 But groups of fields in a complex field can be rearranged, and in carbon fields there's no way to address a field in a group other than group's ordinal position.
 So when getting field's old value, value of field which used to be in current position is retreived instead of the intended one.
 To solve this problem there has to be a way to address a speciefic group of fields irrespective of their position.
 Here this problem is solved by adding an ID field.
 Then, on save a named array of groups based on the ID field is constructed which is used to match new and old group.
*/

/*
 Get fields of a container or a complex field group (both have get_fields), cycle through each field,
 and if it's a complex, add an ID field to each group. Repeat for each group.
 They can be added in the regular way to each group but this is more convenient.
*/
function crb_add_group_id_fields_recursively($container_or_group, $datastore)
{
    $fields = $container_or_group->get_fields();
    foreach ($fields as $field_key => $field) {
        $field_type = $field->get_type();
        if ($field_type == 'complex') {
            $group_names = $field->get_group_names();
            if ($group_names != null) {
                foreach ($group_names as $group_name_key => $group_name) {
                    $group = $field->get_group_by_name($group_name);
                    crb_add_group_id_fields_recursively($group, $datastore);
                    $id_field = Field::make('hidden', '__id__');
                    // Because the field is added outside of the standart
                    // container context the container's datastore has to be added manually.
                    $id_field->set_datastore($datastore);
                    $group->add_fields([ $id_field ]);
                }
            }
        }
    }
}

/*
  Go through each existing container, get its datastore (so the ID fields can be saved) and cycle through each field adding an ID field.
*/
function crb_add_complex_group_id_fields()
{
    $repository = \Carbon_Fields\Carbon_Fields::resolve('container_repository');
    $containers = $repository->get_containers();
    foreach ($containers as $container_key => $container) {
        $datastore = $container->get_datastore();
        crb_add_group_id_fields_recursively($container, $datastore);
    }
}

/*
 The ID fieds are used only when saving complex fields so there's no necessity to register them in client area.
 Priority is 100 because this operation has to be performed after all normal fields are registered.
*/
if (is_admin()) {
    add_action('carbon_fields_register_fields', 'crb_add_complex_group_id_fields', 100);
}

/*
 If an ID field inside a group doesn't yet have a value, put a unique ID into the field to later use it to address the group.
*/
function crb_set_group_id_field_value($field)
{
    if (strpos($field->get_base_name(), '__id__') !== false) {
        if ($field->get_value() == "") {
            $field->set_value(uniqid());
            $field->save();
        }
    }
    return $field;
}
add_filter('carbon_fields_before_field_save', 'crb_set_group_id_field_value');


/*
 Conplex field's value tree is a multidimensional array of groups with fields which can be complex too.
 Go through the tree, find IDs of groups and add group's fields to a named array.
*/
function crb_make_named_group_array_recursively($value_tree)
{
    $named_group_array = [];
    foreach ($value_tree as $field_group_key => $field_group) {
        $fields = [];
        foreach ($field_group as $field_key => $field) {
            if (is_array($field)) {
                if (count($field) == 1) {
                    $fields[$field_key] = $field[0]['value'];
                } else {
                    $named_group_array = array_merge(crb_make_named_group_array_recursively($field), $named_group_array);
                }
            }
        }
        if (isset($field_group['__id__']) && $field_group['__id__'] != '') {
            $group_id = $field_group['__id__'][0]['value'];
            $named_group_array[$group_id] = $fields;
        }
    }
    return $named_group_array;
}


/*
 Go through value tree of a complex field, get ID of each group, get old multilingual value by group ID, update fields in value tree.
*/
function crb_update_value_tree_recursively($value_tree, $named_group_array)
{
    foreach ($value_tree as $field_group_key => $field_group) {
        $group_id = '';
        if (isset($field_group['__id__'])) {
            $group_id = $field_group['__id__'][0]['value'];
        }

        foreach ($field_group as $field_key => $field) {
            if (is_array($field)) {
                if (count($field) == 1) {
                    if ($group_id != '') {
                        if ($field_key != '__id__') {
                            $value = $field[0]['value'];
                            $ml_value = $named_group_array[$group_id][$field_key];
                            $updated_ml_value = wpm_set_new_value($ml_value, $value);
                            $value_tree[$field_group_key][$field_key][0]['value'] = $updated_ml_value;
                        }
                    }
                } else {
                    $value_tree[$field_group_key][$field_key] = crb_update_value_tree_recursively($field, $named_group_array);
                }
            }
        }
    }
    return $value_tree;
}


/*
 Hook into saving of a complex field, get old value tree with multilingual values, sort groups by ID,
 match new values with old multilingual ones by ID, update value tree, let carbon fields put it into the database.
*/
function crb_update_complex_ml_values($complex)
{
    $hierarchy = $complex->get_hierarchy();

    /*
     Empty hierarchy means that this is a first level complex field.
     It contains all children field values in its value tree, even child complex fields.
     So recursive translation shoud be done only on it.
    */
    if (empty($hierarchy)) {
        remove_filter('carbon_fields_datastore_storage_array', 'crb_wpm_extract_translation');
        $old_field = clone $complex;
        $old_field->load();
        $old_value_tree = $old_field->get_value_tree();
        add_filter('carbon_fields_datastore_storage_array', 'crb_wpm_extract_translation');

        $new_value_tree = $complex->get_value_tree();

        $named_group_array = crb_make_named_group_array_recursively($old_value_tree);

        $updated_value_tree = crb_update_value_tree_recursively($new_value_tree, $named_group_array);

        $complex->delete();
        $complex->set_value_tree($updated_value_tree);
    }
    return $complex;
}
add_filter('carbon_fields_before_complex_field_save', 'crb_update_complex_ml_values');


/*
 All carbon fields values get deleted before writing new ones to the database.
 This is necessary for some fields, such as complex fields and association fields,
 these fields can have values added or removed dynamically.
 If the old values weren't deleted, if some dynamic field value gets deleted,
 still existing value would get overwritten, but the deleted ones would just stay forever.
 But here we have to prevent them from being deleted, because we have to get
 old field values which still contain multilingual string so we can update them.
 Conplex fields still have to be deleted, but it's done later, after the old values are retreived.
*/
function crb_delete_field_value_on_save($delete, $field)
{
    $delete_types = [
      'association',
      'media_gallery',
    ];

    $field_type = $field->get_type();

    if (in_array($field_type, $delete_types)) {
        return true;
    }

    return false;
}
add_filter('carbon_fields_should_delete_field_value_on_save', 'crb_delete_field_value_on_save', 10, 2);
