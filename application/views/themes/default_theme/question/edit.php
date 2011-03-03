<?php echo View::factory($theme_dir.'partials/post_form')
             ->set('theme_dir', $theme_dir)
             ->bind('notify_user', $notify_user)
             ->bind('errors', $errors)
             ->set('post', $post)
             ->set('form_type', Helper_PostType::QUESTION)
             ->set('form_action', URL::site(Route::get('question')->uri(array('action' => 'edit', 'id' => $post->id))))
             ->set('form_title', __('Update Question'))
             ->set('button_value', __('Update'))
             ->set('tag_list', $tag_list)
             ->set('user_logged_in', TRUE)
             ->set('token', $token)
             ->render();