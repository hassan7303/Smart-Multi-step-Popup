<?php

/**
 * Plugin Name: Smart Multi-step Popup
 * Description: پاپ‌آپ فرم چندمرحله‌ای با شرط‌ها، زمان‌بندی و نمایش روی اسلاگ‌های مشخص — پیاده‌سازی یک‌فایلی برای نصب سریع.
 * Version: 1.0
 * Author: hassan ali askari
 */

if (!defined('ABSPATH')) exit;

class SMSSmartPopup
{
    private $option_key = 'sms_popups';
    private $submissions_key = 'sms_popup_submissions';

    public function __construct()
    {
        add_action('admin_menu', array($this, 'admin_menu'));
        add_action('admin_init', array($this, 'handle_admin_actions'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_assets'));
        add_action('wp_footer', array($this, 'print_frontend_templates'));
        add_action('wp_ajax_sms_submit', array($this, 'ajax_submit'));
        add_action('wp_ajax_nopriv_sms_submit', array($this, 'ajax_submit'));
    }

    // --- Admin ---
    public function admin_menu()
    {
        add_menu_page('Smart Popups', 'Smart Popups', 'manage_options', 'sms_popups', array($this, 'admin_page'), 'dashicons-feedback', 60);
    }

    public function handle_admin_actions()
    {
        if (!current_user_can('manage_options')) return;
        // save popup (create/update)
        if (isset($_POST['sms_save_popup']) && check_admin_referer('sms_save_popup')) {
            $popups = get_option($this->option_key, array());
            $id = sanitize_text_field($_POST['sms_id']);
            $data = array(
                'id' => $id ? $id : 'p' . time(),
                'title' => sanitize_text_field($_POST['sms_title']),
                'slugs' => array_map('trim', explode(',', sanitize_text_field($_POST['sms_slugs']))),
                'delay' => intval($_POST['sms_delay']),
                'scroll' => intval($_POST['sms_scroll']),
                'reopen_minutes' => intval($_POST['sms_reopen_minutes']),
                'form_json' => wp_unslash($_POST['sms_form_json']),
                'active' => isset($_POST['sms_active']) ? 1 : 0,
            );
            // if id exists replace
            $found = false;
            foreach ($popups as $i => $p) {
                if ($p['id'] === $data['id']) {
                    $popups[$i] = $data;
                    $found = true;
                    break;
                }
            }
            if (!$found) $popups[] = $data;
            update_option($this->option_key, $popups);
            add_settings_error('sms_messages', 'sms-saved', 'Popup saved.', 'updated');
        }

        // delete
        if (isset($_GET['sms_action']) && $_GET['sms_action'] === 'delete' && isset($_GET['id'])) {
            if (!check_admin_referer('sms_delete_' . $_GET['id'])) return;
            $popups = get_option($this->option_key, array());
            $id = sanitize_text_field($_GET['id']);
            $new = array();
            foreach ($popups as $p) if ($p['id'] !== $id) $new[] = $p;
            update_option($this->option_key, $new);
            add_settings_error('sms_messages', 'sms-deleted', 'Popup deleted.', 'updated');
        }

        // export submissions (not heavy)
        if (isset($_GET['sms_action']) && $_GET['sms_action'] === 'export_submissions') {
            if (!check_admin_referer('sms_export')) return;
            $subs = get_option($this->submissions_key, array());
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="sms_submissions_' . date('Ymd_His') . '.csv"');
            $out = fopen('php://output', 'w');
            fputcsv($out, array('popup_id', 'time', 'data'));
            foreach ($subs as $s) fputcsv($out, array($s['popup_id'], date('c', $s['time']), json_encode($s['data'])));
            exit;
        }
    }

    public function admin_page()
    {
        if (!current_user_can('manage_options')) wp_die('Not allowed');
        $popups = get_option($this->option_key, array());
        $subs = get_option($this->submissions_key, array());
        settings_errors('sms_messages');
?>
        <div class="wrap">
            <h1>Smart Multi-step Popups</h1>
            <p>در این پنل می‌توانید پاپ‌آپ‌ها را اضافه کنید. برای تعریف فرم چندمرحله‌ای از JSON استفاده کنید. مثال آماده پایین قرار دارد.</p>

            <h2>Existing Popups</h2>
            <table class="widefat">
                <thead>
                    <tr>
                        <th>Title</th>
                        <th>Slugs</th>
                        <th>Delay(s)</th>
                        <th>Scroll(%)</th>
                        <th>Reopen(min)</th>
                        <th>Active</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($popups as $p): ?>
                        <tr>
                            <td><?php echo esc_html($p['title']); ?></td>
                            <td><?php echo esc_html(implode(', ', $p['slugs'])); ?></td>
                            <td><?php echo intval($p['delay']); ?></td>
                            <td><?php echo intval($p['scroll']); ?></td>
                            <td><?php echo intval($p['reopen_minutes']); ?></td>
                            <td><?php echo $p['active'] ? 'Yes' : 'No'; ?></td>
                            <td>
                                <a class="button" href="?page=sms_popups&edit=<?php echo esc_attr($p['id']); ?>">Edit</a>
                                <a class="button" href="?page=sms_popups&sms_action=delete&id=<?php echo esc_attr($p['id']); ?>&_wpnonce=<?php echo wp_create_nonce('sms_delete_' . $p['id']); ?>">Delete</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <h2><?php echo isset($_GET['edit']) ? 'Edit popup' : 'New popup'; ?></h2>
            <?php
            $editing = null;
            if (isset($_GET['edit'])) {
                foreach ($popups as $p) if ($p['id'] === $_GET['edit']) {
                    $editing = $p;
                    break;
                }
            }
            $example_json = json_encode(array(
                'steps' => array(
                    array('id' => 's1', 'title' => 'مرحله ۱', 'fields' => array(array('type' => 'choice', 'name' => 'pick', 'label' => 'انتخاب کن', 'options' => array('A', 'B')))),
                    array('id' => 's2', 'title' => 'مرحله ۲A', 'condition' => array('field' => 'pick', 'equals' => 'A'), 'fields' => array(array('type' => 'text', 'name' => 'note', 'label' => 'توضیح برای A'))),
                    array('id' => 's3', 'title' => 'مرحله ۲B', 'condition' => array('field' => 'pick', 'equals' => 'B'), 'fields' => array(array('type' => 'text', 'name' => 'note_b', 'label' => 'توضیح برای B'))),
                    array('id' => 's4', 'title' => 'نتیجه', 'fields' => array(array('type' => 'html', 'name' => 'done', 'label' => 'متشکریم!'))),
                )
            ), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            ?>

            <form method="post">
                <?php wp_nonce_field('sms_save_popup'); ?>
                <input type="hidden" name="sms_id" value="<?php echo esc_attr($editing ? $editing['id'] : ''); ?>">
                <table class="form-table">
                    <tr>
                        <th>Title</th>
                        <td><input name="sms_title" class="regular-text" value="<?php echo esc_attr($editing ? $editing['title'] : ''); ?>"></td>
                    </tr>
                    <tr>
                        <th>Slugs (comma separated)</th>
                        <td><input name="sms_slugs" class="regular-text" value="<?php echo esc_attr($editing ? implode(',', $editing['slugs']) : ''); ?>">
                            <p class="description">مثال: contact,pricing,about</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Delay (seconds)</th>
                        <td><input name="sms_delay" class="small-text" value="<?php echo esc_attr($editing ? $editing['delay'] : 5); ?>"></td>
                    </tr>
                    <tr>
                        <th>Scroll percent (0 to disable)</th>
                        <td><input name="sms_scroll" class="small-text" value="<?php echo esc_attr($editing ? $editing['scroll'] : 0); ?>"></td>
                    </tr>
                    <tr>
                        <th>Reopen after (minutes)</th>
                        <td><input name="sms_reopen_minutes" class="small-text" value="<?php echo esc_attr($editing ? $editing['reopen_minutes'] : 60); ?>">
                            <p class="description">پس از بستن پاپ‌آپ دوباره پس از این زمان نمایش داده می‌شود.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Active</th>
                        <td><label><input type="checkbox" name="sms_active" <?php checked($editing ? $editing['active'] : 1, 1); ?>> Active</label></td>
                    </tr>
                    <tr>
                        <th>Form JSON</th>
                        <td>
                            <<textarea name="sms_form_json" ...><?php echo esc_textarea(stripslashes($editing ? $editing['form_json'] : $example_json)); ?></textarea>
                                <p class="description">تعریف: یک آرایه شامل steps. هر step می‌تواند id, title, condition (optional: field, equals), fields[]. field types: choice (options array), text, email, html (برای متن) — مثال بالا را نگاه کن.</p>
                        </td>
                    </tr>
                </table>
                <p><button class="button button-primary" type="submit" name="sms_save_popup">Save popup</button></p>
            </form>

            <h2>Submissions (<?php echo count($subs); ?>)</h2>
            <p><a class="button" href="?page=sms_popups&sms_action=export_submissions&_wpnonce=<?php echo wp_create_nonce('sms_export'); ?>">Export CSV</a></p>
            <table class="widefat">
                <thead>
                    <tr>
                        <th>Popup</th>
                        <th>Time</th>
                        <th>Data</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($subs as $s): ?>
                        <tr>
                            <td><?php echo esc_html($s['popup_id']); ?></td>
                            <td><?php echo esc_html(date('Y-m-d H:i:s', $s['time'])); ?></td>
                            <td>
                                <pre><?php echo esc_html(json_encode($s['data'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)); ?></pre>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
<?php
    }

    // --- Frontend assets ---
    public function enqueue_assets()
    {
        wp_register_style('sms-popup-css', false);
        wp_enqueue_style('sms-popup-css');
        $css = "
        .sms-popup-overlay{position:fixed;left:0;top:0;right:0;bottom:0;background:rgba(0,0,0,0.5);display:flex;align-items:center;justify-content:center;z-index:99999}
        .sms-popup{background:#fff;padding:20px;border-radius:8px;max-width:600px;width:90%;box-shadow:0 10px 30px rgba(0,0,0,.2);    position: relative;}
        .sms-step{display:none}
        .sms-step.active{display:block}
        .sms-popup .sms-actions{margin-top:12px;text-align:right}
        .sms-popup .sms-actions button {
  margin-left: 5px;
}
.sms-popup .sms-exit {
  background: #e74c3c;
  color: #fff;
  border: none;
  border-radius: 5px;
  padding: 6px 12px;
  cursor: pointer;
}
.sms-popup .sms-exit:hover {
  background: #c0392b;
}

        .sms-popup .sms-close{position:absolute;left:12px;top:5px;cursor:pointer;font-size: 17px;}
        ";



        wp_add_inline_style('sms-popup-css', $css);

        wp_register_script('sms-popup-js', false, array('jquery'), null, true);
        global $post;
        $slug = '';
        if (is_front_page()) {
            $slug = 'home';
        } elseif (is_singular() && $post) {
            $slug = $post->post_name;
        }
        wp_localize_script('sms-popup-js', 'smsCurrentSlug', $slug);
        wp_enqueue_script('sms-popup-js');
        $js = $this->get_frontend_js();
        wp_add_inline_script('sms-popup-js', $js);

        // localize with popups data
        $popups = get_option($this->option_key, array());
        wp_localize_script('sms-popup-js', 'smsPopupsData', $popups);
        wp_localize_script('sms-popup-js', 'smsAjax', array('ajaxurl' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('sms_submit')));
    }

    private function get_frontend_js()
    {
        // JS builds DOM from JSON, controls delay, scroll, cookie for reopen, and multi-step form with conditional navigation
        $js = <<<'JS'
(function($){
  function pathMatches(slugs){
    var path = (typeof smsCurrentSlug !== 'undefined' && smsCurrentSlug) ? smsCurrentSlug : location.pathname.replace(/^\//,'').replace(/\/$/,'');
    if (!path) path = 'home';
    for(var i=0;i<slugs.length;i++){
        var s = slugs[i].trim();
        if (s === path || s === '*') return true;
    }
    return false;
  }

  function getCookie(name){
      var v = document.cookie.match('(^|;) ?'+name+'=([^;]*)(;|$)');
      return v?decodeURIComponent(v[2]):null;
  }

  function setCookie(name,val,mins){
      var d = new Date(); d.setTime(d.getTime() + mins*60*1000);
      document.cookie = name+'='+encodeURIComponent(val)+';expires='+d.toUTCString()+';path=/';
  }

  function evaluateCondition(cond, values){
    if (!cond) return true;

    // پشتیبانی از any / all
    if (Array.isArray(cond.any)) {
        return cond.any.some(function(sub){
            return evaluateCondition(sub, values);
        });
    }
    if (Array.isArray(cond.all)) {
        return cond.all.every(function(sub){
            return evaluateCondition(sub, values);
        });
    }

    // حالت کلاسیک field/equals
    var f = cond.field;
    var eq = cond.equals;
    if (typeof values[f] === 'undefined') return false;
    return String(values[f]) === String(eq);
}


  function buildPopup(popup){
      try{
          var def = (typeof popup.form_json === 'string') ? JSON.parse(popup.form_json) : popup.form_json;
      }catch(e){ console.error('Invalid JSON for popup', popup); return null; }

      var overlay = document.createElement('div'); overlay.className='sms-popup-overlay';
      var box = document.createElement('div'); box.className='sms-popup';
    box.innerHTML = `
  <span class="sms-close">✖</span>
  <div class="sms-steps"></div>
  <div class="sms-actions">
    <button class="sms-prev">قبلی</button>
    <button class="sms-next">بعدی</button>
  </div>`;

      overlay.appendChild(box);

      var stepsWrap = box.querySelector('.sms-steps');
      def.steps.forEach(function(s, idx){
          var step = document.createElement('div'); step.className='sms-step'; step.dataset.stepId = s.id || ('s'+idx);
          var html = '<h3>'+ (s.title || '') +'</h3>';
          (s.fields||[]).forEach(function(f){
              if (f.type==='choice'){
                  html += '<div class="sms-field"><label>'+ (f.label||'') +'</label><div>';
                  (f.options||[]).forEach(function(opt,i){
                      html += '<label><input type="radio" name="'+f.name+'" value="'+opt+'"> '+opt+'</label> ';
                  });
                  html += '</div></div>';
              } else if (f.type==='text' || f.type==='email' || f.type==='tel'){
    html += '<div class="sms-field"><label>'+ (f.label||'') + (f.required?' *':'') +'</label><input type="'+f.type+'" name="'+f.name+'" '+(f.required?'required':'')+'></div>';
}
 else if (f.type==='html'){
                  html += '<div class="sms-field">'+ (f.label || '') +'</div>';
              }else if (f.type==='checkbox'){
    html += '<div class="sms-field"><label>'+ (f.label||'') +'</label><div>';
    (f.options||[]).forEach(function(opt,i){
        html += '<label><input type="checkbox" name="'+f.name+'" value="'+opt+'"> '+opt+'</label> ';
    });
    html += '</div></div>';
}
          });
          step.innerHTML += html;
          stepsWrap.appendChild(step);
      });

      return {overlay:overlay,def:def,box:box};
  }

  function showPopup(popup){
      var built = buildPopup(popup);
      if (!built) return;
      var overlay = built.overlay; var box = built.box; var def = built.def;
      document.body.appendChild(overlay);

      var steps = box.querySelectorAll('.sms-step');
      var cur = 0; var values = {};

      function goto(index){
          if (index<0) index=0; if (index>=steps.length) index=steps.length-1;
          var i = index;
          while(i<steps.length){
              var sid = steps[i].dataset.stepId;
              var stepDef = def.steps.find(function(x){return (x.id||'') === sid;});
              if (!stepDef) break;
              if (!evaluateCondition(stepDef.condition, values)) { i++; continue; }
              break;
          }
          steps.forEach(function(s){s.classList.remove('active');});
          if (i<steps.length) steps[i].classList.add('active');
          cur = i;
          updateButtons();
      }

      function updateButtons(){
          box.querySelector('.sms-prev').style.display = (cur>0)?'inline-block':'none';
          box.querySelector('.sms-next').textContent = (cur===steps.length-1)?'ارسال':'بعدی';
      }

      function collectValues(){
    values = {};
    $(steps).find(':input[name]').each(function(){
        var name = $(this).attr('name');
        var type = $(this).attr('type');
        if (!name) return;

        if (type === 'checkbox') {
            if ($(this).is(':checked')) {
                if (!Array.isArray(values[name])) values[name] = [];
                values[name].push($(this).val());
            }
        }
        else if (type === 'radio') {
            if ($(this).is(':checked')) values[name] = $(this).val();
        }
        else {
            values[name] = $(this).val();
        }
    });
}


      function validateStep(){
          var stepDef = def.steps[cur];
          var stepEl = $(steps[cur]);
          var valid = true;
          (stepDef.fields || []).forEach(function(f){
              if (f.required){
                  var val = values[f.name];
                  if (!val || val===''){
                      alert('لطفاً فیلد "'+ f.label +'" را پر کنید.');
                      valid = false;
                  }
              }
          });
          return valid;
      }

      // next
      box.querySelector('.sms-next').addEventListener('click', function(){
          collectValues();
          if (!validateStep()) return;
          if (cur === steps.length-1){
              var payload = {popup_id: popup.id, data: values, _wpnonce: smsAjax.nonce};
              $.post(smsAjax.ajaxurl, {action:'sms_submit', payload: JSON.stringify(payload)}, function(resp){
                  try{ var r = JSON.parse(resp); }catch(e){ console.log(resp); }
                  if (typeof r !== 'undefined' && r.success) {
                      steps[cur].innerHTML = '<div><h3>متشکریم!</h3><p>پاسخ شما ثبت شد.</p></div>';
                      setTimeout(function(){ document.body.removeChild(overlay); }, 1200);
                  } else {
                      alert('خطا در ارسال');
                  }
              });
          } else {
              goto(cur+1);
          }
      });

      // prev
      box.querySelector('.sms-prev').addEventListener('click', function(){
          var prev = cur-1; while(prev>=0){
              var sid = steps[prev].dataset.stepId; var stepDef = def.steps.find(function(x){return (x.id||'')===sid;});
              if (!stepDef || evaluateCondition(stepDef.condition, values)) break; prev--; }
          if (prev<0) prev=0; goto(prev);
      });

      // close
      box.querySelector('.sms-close').addEventListener('click', function(){
          document.body.removeChild(overlay);
          setCookie('sms_popup_'+popup.id, 'closed', popup.reopen_minutes || 60);
      });

      // auto next for choice
     def.steps.forEach(function(stepDef, idx){
    var step = steps[idx];
    var autoNext =
        stepDef.autoNextOnChoice ||
        (stepDef.fields || []).some(f => f.autoNextOnChoice);

    if (autoNext) {
        $(step).find('input[type=radio]').on('change', function(){
            collectValues();
            if (validateStep()) { 
                collectValues(); 
                goto(cur+1);
            }
        });
    }
});

box.querySelector('.sms-exit').addEventListener('click', function(){
    document.body.removeChild(overlay);
    setCookie('sms_popup_'+popup.id, 'closed', popup.reopen_minutes || 60);
});
      goto(0);
  }

  function checkAndMaybeShow(popup){
      if (!popup.active) return;
      if (!pathMatches(popup.slugs || [])) return;
      if (getCookie('sms_popup_'+popup.id)) return;
      var shown = false;
      var onScrollHandler = null;
      function tryShow(){ if (shown) return; shown = true; showPopup(popup); window.removeEventListener('scroll', onScrollHandler); }
      if (popup.scroll && parseInt(popup.scroll)>0){
          onScrollHandler = function(){
              var pct = (window.scrollY + window.innerHeight) / document.body.scrollHeight * 100;
              if (pct >= parseInt(popup.scroll)) tryShow();
          };
          window.addEventListener('scroll', onScrollHandler);
      } else {
          setTimeout(tryShow, (parseInt(popup.delay) || 3)*1000);
      }
  }

  $(function(){
      if (typeof smsPopupsData === 'undefined') return;
      smsPopupsData.forEach(function(p){
          if (!p.id) p.id = 'p'+Math.floor(Math.random()*100000);
          try { p.form_json = p.form_json || '{}'; } catch(e){}
          checkAndMaybeShow(p);
      });
  });
})(jQuery);

JS;
        return $js;
    }

    public function print_frontend_templates()
    {
        // none - everything inline
    }

    // --- AJAX submit ---
    public function ajax_submit()
    {
        if (!isset($_POST['payload'])) wp_die('0');
        $payload = json_decode(stripslashes($_POST['payload']), true);
        if (!$payload) wp_die(json_encode(array('success' => false)));
        // store
        $subs = get_option($this->submissions_key, array());
        $subs[] = array('popup_id' => sanitize_text_field($payload['popup_id']), 'time' => time(), 'data' => (array)$payload['data']);
        update_option($this->submissions_key, $subs);
        // optionally email
        // wp_mail(get_option('admin_email'), 'New popup submission', print_r($payload,true));
        echo json_encode(array('success' => true));
        wp_die();
    }
}

new SMSSmartPopup();

?>