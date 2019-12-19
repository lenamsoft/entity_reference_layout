(function ($, Drupal, drupalSettings) {

    'use strict';
  
    var drake;
  
    Drupal.behaviors.erlWidget = {
      attach: function attach(context, settings) {

        var updateFields = function($container) {
          // Set deltas:
          var delta = -1;
          $container.find('.erl-weight, .erl-new-item-delta').each(function(index, item){
            if ($(item).hasClass('erl-weight')) {
              delta++;
            }
            $(item).val(delta + '');
          });
          $container.find('input.erl-region').each(function(index, item){
            $(item).val(getRegion($(item)));
          });
        }

        var updateDisabled = function($container) {
          if ($container.find('.erl-disabled-items .erl-item').length > 0) {
            $container.find('.erl-disabled-items__description').hide();
          }
          else {
            $container.find('.erl-disabled-items__description').show();
          }
        }

        var getRegion = function($el) {
          var regEx = /erl-layout-region--([a-z0-9A-Z_]*)/,
            regionName = '',
            $container = $el.is('.erl-layout-region') ? $el : $el.parents('.erl-layout-region');
          if ($container.length) {
            var matches = $container[0].className.match(regEx);
            if (matches && matches.length >= 2) {
              regionName = matches[1];
            }
          }
          return regionName;
        }

        var moveUp = function($item, $container) {
          var $item = $(this).parents('.erl-item:first'),
              $container = $item.parent();
          // We're first, jump up to next available region.
          if ($item.prev('.erl-item').length == 0) {
            // Previous region, same layout.
            if ($container.prev('.erl-layout-region').length) {
              $container.prev('.erl-layout-region').append($item);
            }
            // Otherwise jump to last region in previous layout.
            else if ($container.closest('.erl-layout').prev().find('.erl-layout-region:last-child').length) {
              $container.closest('.erl-layout').prev().find('.erl-layout-region:last-child .erl-add-content__container').before($item);
            }
          }
          else {
            $item.after($item.prev());
          }
          updateFields($container.closest('.erl-field'));
        }
  
        var moveDown = function($item, $container) {
          var $item = $(this).parents('.erl-item:first'),
              $container = $item.parent();
          // We're first, jump down to next available region.
          if ($item.next('.erl-item').length == 0) {
            // Next region, same layout.
            if ($container.next('.erl-layout-region').length) {
              $container.next('.erl-layout-region').prepend($item);
            }
            // Otherwise jump to first region in next layout.
            else if ($container.closest('.erl-layout').next().find('.erl-layout-region:first-child').length) {
              $container.closest('.erl-layout').next().find('.erl-layout-region:first-child .erl-add-content__container').before($item);
            }
          }
          else {
            $item.before($item.next());
          }
          updateFields($container.closest('.erl-field'));
        }

        var enhanceRadioSelect = function() {
          $('.layout-radio-item').click(function(){
              $(this).find('input[type=radio]').prop("checked", true).trigger("change");
              $(this).siblings().removeClass('active');
              $(this).addClass('active');
          });
          $('.layout-radio-item').each(function(){
            if ($(this).find('input[type=radio]').prop("checked")) {
              $(this).addClass('active');
            }
          });
        }

        // Add create item links.
        $('.erl-field', context).once('erl-add-item-links').each(function(index, item){

          var $widget_container = $(item),
              $submit_button = $widget_container.find('input.erl-add-item'),
              $select = $widget_container.find('select.erl-item-type'),
              $region_input = $widget_container.find('.erl-new-item-region'),
              $parent_input = $widget_container.find('.erl-new-item-parent'),
              $options = $select.find('option').map(function(i, opt){
                var icon = '', 
                    type = $(opt).val(),
                    label = $(opt).text();
                if (drupalSettings.erlIcons && drupalSettings.erlIcons['icon_' + type]) {
                  icon = '<img src="' + drupalSettings.erlIcons['icon_' + type] + '" />';
                }
                return $('<button class="erl-add-content__item" data-new-item-type="' + type + '">' + icon + label + '</button>')[0];
              }),
              $button_group = $('<div class="erl-add-content__group hidden">').append($options),
              $link_group = $('<div class="erl-add-content__container">')
                .append('<button class="erl-add-content__toggle">+</button>')
                .append($button_group);

          $widget_container.find('.erl-layout-region').each(function(region_index, region_item){
            var $region_link_group = $link_group.clone();
            $region_link_group.find('button.erl-add-content__toggle').each(function(index, item){
              $(item)
              .on('click', function(e){
                $(e.target).focus();
                return false;
              })
              .on('click', function(e){
                var $b = $(e.target);
                $b.parent().find('.erl-add-content__group').toggleClass('hidden');
                $b.toggleClass('active');
                $b.text($b.text() == '+' ? '-' : '+');
                return false;
              });
            });
            $region_link_group.find('button.erl-add-content__item').each(function(button_index, button_item){
              $(button_item).click(function(){
                let region = getRegion($(this).parents('.erl-layout-region')),
                    parent = $(this).parents('.erl-layout').find('.erl-weight').val();
                $region_input.val(region);
                $parent_input.val(parent);
                $select.val($(this).attr('data-new-item-type'));
                $submit_button.trigger('mousedown').trigger('click');
                return false;
              });
            });
            $(region_item).append($region_link_group);
          });
        });

        // Add create section links.
        $('.erl-field', context).once('erl-add-section').each(function(index, item){
          var $widget_container = $(item),
              $submit_button = $widget_container.find('input.erl-add-section'),
              $region_input = $widget_container.find('.erl-new-item-region'),
              $parent_input = $widget_container.find('.erl-new-item-parent'),
              $link = $('<div class="erl-add-content--single">')
                .append('<button><span class="icon">+</span>' + $submit_button.val() + '</button>');

          $widget_container.find('.erl-empty, .erl-layout').each(function(layout_index, layout_item){
            var $layout_link = $link.clone();
            $layout_link.find('button').click(function(){
              let parent = $(this).parents('.erl-layout').find('.erl-weight').val();
              $parent_input.val(parent);
              // Sections don't go in regions.
              $region_input.val('');
              console.log($submit_button);
              $submit_button.trigger('mousedown').trigger('click');
              return false;
            });
            $(layout_item).append($layout_link);
          });
        });        

        // Load forms in dialog.
        $('.erl-field .erl-form', context).once('erl-dialog').each(function(index, item){
          var dialogConfig = {
            width: '800px',
            title: $(item).find('[data-dialog-title]').attr('data-dialog-title'),
            maxHeight: '100%',
            appendTo: $('.erl-form').parent(),
            draggable: true,
            autoResize: true,
            open: function (event, ui) {
              enhanceRadioSelect();
              var $element = $(event.target);
              $element.find('.erl-cancel').on('mousedown click', function (event, data) {
                // If this has not been triggered by a dialog close event, ensure close is triggered.
                if (!data || !data.fromClose) {
                  $element.dialog('close');
                }
              });              
            },
            beforeClose: function(event, ui) {
              $(event.target).find('.erl-cancel').trigger('mousedown', [{fromClose: true}]).trigger('click', [{fromClose: true}]);
            }
          },
          dialog = Drupal.dialog('.erl-form', dialogConfig);
          dialog.showModal();
        });

        // Initialize drag/drop.
        var drake = [];
        $('.erl-field', context).once('erl-drag-drop').each(function(index, item){
          $(item).addClass('dragula-enabled');

          // Turn on drag and drop if dragula function exists.
          if (typeof dragula !== 'undefined') {

            // Add layout handles.
            $('.erl-item').each(function(index, item){
              $('<div class="layout-controls">')
                .append($('<div class="layout-handle">'))
                .append($('<div class="layout-up">').click(moveUp))
                .append($('<div class="layout-down">').click(moveDown))
                .prependTo(item);
            });
            var drake = dragula($('.erl-layout-wrapper, .erl-layout-region, .erl-disabled-wrapper', item).get(), {
              moves: function(el, container, handle) {
                return handle.className.toString().indexOf('layout-handle') >= 0;
                },

              accepts: function(el, target, source, sibling) {
                var $el = $(el);

                // Regions always have to have a sibling,
                // forcing layout controls to be last element in container.
                if (!$el.is('.erl-layout') && !sibling) {
                  //console.log('no sibling');
                  return false;
                }

                // Layouts can never go inside another layout.
                if ($el.is('.erl-layout')) {
                  if ($(target).parents('.erl-layout').length) {
                    //console.log('no nested layouts');
                    return false;
                  }
                }

                // Layouts can not be dropped into disabled (only individual items).
                if ($el.is('.erl-layout')) {
                  if ($(target).is('.erl-disabled-wrapper')) {
                    //console.log('no disabled layouts');
                    return false;
                  }
                }
                // Require non-layout items to be dropped in a layout.
                else {
                  if($(target).parents('.erl-layout').length == 0 && !$(target).is('.erl-disabled-wrapper')) {
                    //console.log('items must go in layouts');
                    return false;
                  }
                }

                return true;
              }
            });

            drake.on('drop', function(el, target, source, sibling){
              updateFields($(el).closest('.erl-field'));
              updateDisabled($(el).closest('.erl-field'));
            });
          }

        });

        // Update hidden fields.
        $('.erl-field', context).once('erl-update-fields').each(function(index, item){
          updateFields($(item));
          updateDisabled($(item));          
        });
      }
    };

})(jQuery, Drupal, drupalSettings);
