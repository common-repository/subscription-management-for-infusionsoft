jQuery(document).ready(function($)

{
  $('.chk_all').click(function () {
    $('.chk_boxes').prop('checked', this.checked);
  })
});
