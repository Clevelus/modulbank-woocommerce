<!DOCTYPE html>
<html>
<head>
	<title>...</title>
	<script type="text/javascript">
	window.onload = function() {
		document.getElementById('submit-button').style.display = 'none';
        document.getElementById('autosubmit-form').submit();
	};
	</script>
</head>
<body>
	<form action="<?php echo $ff->get_url(); ?>" method="post" id="autosubmit-form">
	    <?php echo \FPayments\PaymentForm::array_to_hidden_fields($data); ?>
	    <input type="submit" id='submit-button' value="<?php _e('Переход на платежную страницу'); ?>">
	</form>
</body>
</html>
