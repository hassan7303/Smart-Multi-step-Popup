<?php

/**
 * Plugin Name: Smart Multi-step Popup
 * Description: پاپ‌آپ فرم چندمرحله‌ای با شرط‌ها، زمان‌بندی و نمایش روی اسلاگ‌های مشخص — پیاده‌سازی یک‌فایلی برای نصب سریع.
 * Version: 1.0
 * Author: hassan ali askari 
 */

if (!defined('ABSPATH')) exit;
register_activation_hook(__FILE__, function () {
  global $wpdb;
  $table_name = $wpdb->prefix . 'sms_popup_submissions';
  $charset_collate = $wpdb->get_charset_collate();

  $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        popup_id VARCHAR(100) NOT NULL,
        submitted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        data LONGTEXT NOT NULL,
        PRIMARY KEY  (id)
    ) $charset_collate;";

  require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
  dbDelta($sql);
});

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

    // export submissions خروجی میگیره ولی کامل نیست
    // if (isset($_GET['sms_action']) && $_GET['sms_action'] === 'export_submissions') {
    //     if (!check_admin_referer('sms_export')) return;
    //     $subs = get_option($this->submissions_key, array());
    //     header('Content-Type: text/csv');
    //     header('Content-Disposition: attachment; filename="sms_submissions_'.date('Ymd_His').'.csv"');
    //     $out = fopen('php://output','w');
    //     fputcsv($out, array('popup_id','time','data'));
    //     foreach ($subs as $s) fputcsv($out, array($s['popup_id'], date('c',$s['time']), json_encode($s['data'])));
    //     exit;
    // }
  }

  public function admin_page()
  {
    if (!current_user_can('manage_options')) wp_die('Not allowed');
    $popups = get_option($this->option_key, array());
    // $subs = get_option($this->submissions_key, array()); // 🟡 نمایش ارسال‌ها غیرفعال شد
    settings_errors('sms_messages');
?>
    <div class="wrap">
      <h1>Smart Multi-step Popups</h1>
      <p>در این پنل می‌توانید پاپ‌آپ‌ها را اضافه کنید. برای تعریف فرم چندمرحله‌ای از JSON استفاده کنید.</p>

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
            <th>Slugs</th>
            <td><input name="sms_slugs" class="regular-text" value="<?php echo esc_attr($editing ? implode(',', $editing['slugs']) : ''); ?>"></td>
          </tr>
          <tr>
            <th>Delay</th>
            <td><input name="sms_delay" class="small-text" value="<?php echo esc_attr($editing ? $editing['delay'] : 5); ?>"></td>
          </tr>
          <tr>
            <th>Scroll %</th>
            <td><input name="sms_scroll" class="small-text" value="<?php echo esc_attr($editing ? $editing['scroll'] : 0); ?>"></td>
          </tr>
          <tr>
            <th>Reopen (min)</th>
            <td><input name="sms_reopen_minutes" class="small-text" value="<?php echo esc_attr($editing ? $editing['reopen_minutes'] : 60); ?>"></td>
          </tr>
          <tr>
            <th>Active</th>
            <td><label><input type="checkbox" name="sms_active" <?php checked($editing ? $editing['active'] : 1, 1); ?>> Active</label></td>
          </tr>
          <tr>
            <th>Form JSON</th>
            <td><textarea name="sms_form_json" rows="10" class="large-text code"><?php echo esc_textarea(stripslashes($editing ? $editing['form_json'] : '')); ?></textarea></td>
          </tr>
        </table>
        <p><button class="button button-primary" type="submit" name="sms_save_popup">Save popup</button></p>
      </form>

      <?php /*
            <h2>Submissions (<?php echo count($subs); ?>)</h2>
            <p><a class="button" href="?page=sms_popups&sms_action=export_submissions&_wpnonce=<?php echo wp_create_nonce('sms_export'); ?>">Export CSV</a></p>
            <table class="widefat"><thead><tr><th>Popup</th><th>Time</th><th>Data</th></tr></thead><tbody>
            <?php foreach ($subs as $s): ?>
                <tr><td><?php echo esc_html($s['popup_id']); ?></td><td><?php echo esc_html(date('Y-m-d H:i:s',$s['time'])); ?></td><td><pre><?php echo esc_html(json_encode($s['data'], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)); ?></pre></td></tr>
            <?php endforeach; ?>
            </tbody></table>
            */ ?>
    </div>
<?php
  }


  // --- Frontend assets ---
  public function enqueue_assets()
  {
    wp_register_style('sms-popup-css', false);
    wp_enqueue_style('sms-popup-css');
    $css = "
        :root {
  --sms-primary: var(--wd-primary-color, #795548);
}
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
/* 🎨 استایل عمومی فرم پاپ‌آپ */
.sms-popup {
  font-family: inherit;
}

/* دکمه‌های پاپ‌آپ */
.sms-popup .sms-next,
sms-submit-btn,
.sms-popup .sms-prev {
  background: var(--sms-primary);
  color: #fff;
  border: none;
  border-radius: 8px;
  padding: 8px 18px;
  cursor: pointer;
  font-weight: 500;
  transition: all 0.25s ease;
}

/* افکت hover — همون رنگ فقط کمی تیره‌تر */
.sms-popup .sms-next:hover,
.sms-popup .sms-prev:hover {
  filter: brightness(90%);
  transform: translateY(-2px);
  box-shadow: 0 4px 10px rgba(0, 0, 0, 0.25);
}

/* دکمه بستن (✖) هم از رنگ قالب */
.sms-popup .sms-close {
  color: var(--sms-primary);
  cursor: pointer;
  transition: color 0.25s ease, transform 0.25s ease;
}
.sms-popup .sms-close:hover {
  color: #5d4037; /* اگه رنگ قالب نبود، یه قهوه‌ای تیره‌تر */
  transform: scale(1.1);
}

/* کادر مراحل */
.sms-popup .sms-step {
  background: #fff;
  padding: 25px 30px;
  border-radius: 12px;
  box-shadow: 0 2px 10px rgba(0,0,0,0.15);
}


        .sms-popup .sms-close{color: var(--sms-primary, #8B4513);position:absolute;left:12px;top:5px;cursor:pointer;font-size: 17px;}
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
    if (Array.isArray(cond.any)) return cond.any.some(c => evaluateCondition(c, values));
    if (Array.isArray(cond.all)) return cond.all.every(c => evaluateCondition(c, values));
    if (!cond.field) return true;
    var val = values[cond.field];
    return String(val) === String(cond.equals);
  }

  function buildPopup(popup){
    try{ var def = (typeof popup.form_json === 'string') ? JSON.parse(popup.form_json) : popup.form_json; }
    catch(e){ console.error('Invalid JSON', e); return null; }

    var overlay = $('<div class="sms-popup-overlay"><div class="sms-popup"><span class="sms-close">✖</span><div class="sms-steps"></div><div class="sms-actions"><button class="sms-prev">قبلی</button><button class="sms-next">بعدی</button></div></div></div>');
    var stepsWrap = overlay.find('.sms-steps');

    (def.steps || []).forEach(function(s, i){
      var step = $('<div class="sms-step" data-step-id="'+(s.id||('s'+i))+'"><h3>'+(s.title||'')+'</h3></div>');
    (s.fields || []).forEach(function(f){
    // 📘 نوع جدید: note (توضیحات با کادر سبز)
    if (f.type === 'note') {
        var note = $('<div class="sms-note"></div>')
            .html(f.content || '')
            .css({
                'background': '#e6f4ea',
                'border': '1px solid #66bb6a',
                'color': '#2e7d32',
                'padding': '10px 15px',
                'border-radius': '8px',
                'margin-bottom': '15px',
                'font-size': '15px',
                'line-height': '1.6',
                'text-align': 'center'
            });
        step.prepend(note);
        return; // بقیه‌ی انواع رو رد کن
    }

    var html = '';
    if (f.type === 'choice') {
        html += '<div class="sms-field"><label>'+f.label+'</label><div>';
        (f.options || []).forEach(function(opt){
            html += '<label><input type="radio" name="'+f.name+'" value="'+opt+'"> '+opt+'</label> ';
        });
        html += '</div></div>';
    } else if (['text','email','tel'].includes(f.type)) {
        html += '<div class="sms-field"><label>'+f.label+(f.required?' *':'')+'</label><input type="'+f.type+'" name="'+f.name+'" '+(f.required?'required':'')+'></div>';
    } else if (f.type === 'checkbox') {
        html += '<div class="sms-field"><label>'+f.label+'</label><div>';
        (f.options || []).forEach(opt => {
            html += '<label><input type="checkbox" name="'+f.name+'" value="'+opt+'"> '+opt+'</label> ';
        });
        html += '</div></div>';
    } else if (f.type === 'html') {
        html += '<div class="sms-field">'+f.label+'</div>';
    }else if (f.type === 'button' && f.action === 'submit') {
    html += '<div class="sms-field"><button type="button" class="sms-submit-btn">'+(f.label||'ارسال')+'</button></div>';
}


    step.append(html);
});

      stepsWrap.append(step);
    });
(def.steps || []).forEach(function(s){
  if (s.autoSubmit) s._autoSubmit = true;
});

    return { overlay, def };
  }

  function showPopup(popup){
    var built = buildPopup(popup); if (!built) return;
    var {overlay, def} = built;
    $('body').append(overlay);

    var steps = overlay.find('.sms-step');
    var cur = 0, values = {};

    function collectValues(){
      values = {};
      overlay.find(':input[name]').each(function(){
        var n = $(this).attr('name'), t = $(this).attr('type');
        if (t === 'radio'){ if($(this).is(':checked')) values[n] = $(this).val(); }
        else if (t === 'checkbox'){ if(!values[n]) values[n]=[]; if($(this).is(':checked')) values[n].push($(this).val()); }
        else values[n] = $(this).val();
      });
    }

   function ChangeFaNumberToEn(str){
  if(!str) return '';
  const persian = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
  const arabic  = ['٩','٨','٧','٦','٥','٤','٣','٢','١','٠'];
  for(let i=0;i<10;i++){
    str = str.replace(new RegExp(persian[i],'g'), i).replace(new RegExp(arabic[i],'g'), i);
  }
  return str;
}

function isValidMobile(m){
  m = ChangeFaNumberToEn(m || '');
  return /^(\+98|0098|098|0|98)?9\d{9}$/.test(m);
}

function showError(msg){
  let errBox = overlay.find('.sms-error');
  if(!errBox.length){
    errBox = $('<div class="sms-error" style="color:#e74c3c;font-weight:bold;margin-bottom:10px;text-align:center;"></div>');
    overlay.find('.sms-popup').prepend(errBox);
  }
  errBox.text(msg).fadeIn();
}

function validateStep(){
  overlay.find('.sms-error').remove(); // پاک کردن خطا قبلی
  var sDef = def.steps[cur]; var valid = true;
  (sDef.fields||[]).forEach(function(f){
    let val = values[f.name];
    if (f.required && (!val || val==='')){
      showError('لطفاً یک مورد را انتخاب کنید.');
      valid = false;
      return false;
    }

    // 👇 چک شماره موبایل
    if (f.type === 'tel' && val){
      val = ChangeFaNumberToEn(val);
      if (!isValidMobile(val)){
        showError('شماره موبایل معتبر نیست.'); 
        valid = false;
        return false;
      }
      values[f.name] = val; // عدد تبدیل‌شده رو ذخیره کنیم
    }
  });
  return valid;
}


 function visibleStepIndex(index){
  // پیدا کردن اولین مرحله‌ی معتبر از index به بعد
  for (var i=index;i<steps.length;i++){
    var sid = $(steps[i]).data('step-id');
    var sDef = def.steps.find(x=>x.id===sid);
    if (!sDef || evaluateCondition(sDef.condition, values)) return i;
  }
  return -1;
}
function visibleStepIndexBackward(index){
  // پیدا کردن اولین مرحله‌ی معتبر از index به قبل
  for (var i=index;i>=0;i--){
    var sid = $(steps[i]).data('step-id');
    var sDef = def.steps.find(x=>x.id===sid);
    if (!sDef || evaluateCondition(sDef.condition, values)) return i;
  }
  return -1;
}
function submitForm() {
  var payload = { popup_id: popup.id, data: values, senderButton: lastSender, _wpnonce: smsAjax.nonce };

  // پیام موقت
  var msgBox = $('<div class="sms-message" style="text-align:center;margin-top:15px;font-weight:bold;color:#555;">در حال ارسال...</div>');
  overlay.find('.sms-popup').append(msgBox);

  $.post(smsAjax.ajaxurl, { action: 'sms_submit', payload: JSON.stringify(payload) })
    .done(function (res) {
      overlay.find('.sms-message').remove();

      var sDef = def.steps[cur];
      var targetId = (sDef && sDef.onSubmitShowStep) ? sDef.onSubmitShowStep : null;

      // اگه onSubmitShowStep مشخص شده بود → بریم اون استپ و بس
      if (targetId) {
        var nextIndex = def.steps.findIndex(function(s){ return s.id === targetId; });
        if (nextIndex !== -1) {
          goto(nextIndex);
          return; // 🧠 بعدش دیگه هیچ کاری نکن 
        }
      }

      // اگه مرحله‌ای برای نمایش مشخص نشده بود، دیگه کاری نکن
      // ❌ هیچ پیام پیش‌فرض سبزی اضافه نشه
    })
    .fail(function () {
      overlay.find('.sms-message').remove();
      overlay.find('.sms-step, .sms-actions').remove();
      overlay.find('.sms-popup').append(
        '<div style="color:red;font-weight:bold;text-align:center;font-size:16px;padding:40px 10px;">❌ خطا در ارسال. لطفاً دوباره تلاش کنید.</div>'
      );
    });
}







function goto(index) {
  var i = visibleStepIndex(index);
  if (i < 0) return;
  steps.hide().eq(i).show();
  cur = i;
  updateButtons();

  var sDef = def.steps[cur];

  // 🔹 اگه مرحله autoSubmit داشت، فرم رو بفرست
 if (sDef._autoSubmit) {
  collectValues();
  if (!validateStep()) return;
  lastSender = 'auto';
  submitForm();
}


  // 🔹 اگه hideButtons بود، دکمه‌ها رو قایم کن
  overlay.find('.sms-actions').toggle(!sDef.hideButtons);
}



function updateButtons() {
  overlay.find('.sms-prev').toggle(visibleStepIndexBackward(cur - 1) >= 0);

  var isLastStep = true; // فرض می‌کنیم این آخره
  for (var i = cur + 1; i < def.steps.length; i++) {
    var nextStep = def.steps[i];
    if (!nextStep.hideButtons) {
      isLastStep = false;
      break;
    }
  }
var nextBtn = overlay.find('.sms-next');
   if (isLastStep) {
    nextBtn.text('ارسال');
    nextBtn.removeClass('sms-next').addClass('sms-submit-btn');
  } else {
    nextBtn.text('بعدی');
    nextBtn.removeClass('sms-submit-btn').addClass('sms-next');
  }
}




   overlay.find('.sms-prev').on('click', function(){
  collectValues();
  var prev = visibleStepIndexBackward(cur - 1);
  if (prev >= 0) goto(prev);
});

    overlay.find('.sms-next').on('click', function(){
  collectValues();
  if (!validateStep()) return;
  if (cur === steps.length - 1){
    lastSender = 'manual';
    submitForm();
  } else {
    goto(cur + 1);
  }
});

overlay.on('click', '.sms-submit-btn', function(){
  collectValues();
  if (!validateStep()) return;
  lastSender = 'manual';
  submitForm();
});

    overlay.find('.sms-close').on('click', function(){
      overlay.remove();
      setCookie('sms_popup_'+popup.id, 'closed', popup.reopen_minutes || 60);
    });

    // auto-next on choice
    (def.steps||[]).forEach(function(s, i){
      var auto = s.autoNextOnChoice || (s.fields||[]).some(f=>f.autoNextOnChoice);
      if (auto){
        $(steps[i]).find('input[type=radio]').on('change', function(){
          collectValues();
          if (validateStep()) goto(cur+1);
        });
      }
    });

    goto(0);
  }

  function checkAndMaybeShow(popup){
    if (!popup.active || !pathMatches(popup.slugs||[])) return;
    if (getCookie('sms_popup_'+popup.id)) return;
    var shown = false;
    var show = ()=>{ if (shown) return; shown=true; showPopup(popup); };
   if (popup.scroll > 0) {
  // حالت اسکرول: هر وقت رسید، نمایش بده
  $(window).on('scroll.smsPopup', function () {
    var pct = (window.scrollY + window.innerHeight) / document.body.scrollHeight * 100;
    if (pct >= popup.scroll) {
      show();
      $(window).off('scroll.smsPopup'); // دیگه دوبار نیاد
    }
  });
}

// حالت زمان: بعد از delay هم بیاد
setTimeout(show, (popup.delay || 3) * 1000);
  }

  $(function(){
    (smsPopupsData||[]).forEach(checkAndMaybeShow);
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
    global $wpdb;
    $table = $wpdb->prefix . 'sms_popup_submissions'; // ✅ جدول دیتابیس خودت

    if (empty($_POST['payload'])) {
      wp_send_json_error(['msg' => 'no payload']);
    }

    $payload = json_decode(stripslashes($_POST['payload']), true);
    if (!$payload) {
      wp_send_json_error(['msg' => 'invalid json']);
    }

    $popup_id = sanitize_text_field($payload['popup_id']);
    $data     = wp_json_encode($payload['data'], JSON_UNESCAPED_UNICODE);
    $sender   = sanitize_text_field($payload['senderButton'] ?? '');
    $ip       = $_SERVER['REMOTE_ADDR'];

    // ✅ درج در جدول سفارشی
    $ok = $wpdb->insert(
      $table,
      [
        'popup_id'      => $popup_id,
        'data'          => $data,
        'sender'        => $sender,
        'ip_address'    => $ip,
        'submitted_at'  => current_time('mysql'),
      ],
      ['%s', '%s', '%s', '%s', '%s']
    );

    if ($ok) {
      wp_send_json_success(['id' => $wpdb->insert_id]);
    } else {
      error_log('❌ sms_submit db error: ' . $wpdb->last_error);
      wp_send_json_error(['msg' => 'db insert failed']);
    }
  }
}

new SMSSmartPopup();

?>