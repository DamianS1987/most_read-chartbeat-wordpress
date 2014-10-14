most_read-chartbeat-wordpress
=============================
this plugin is still under development

Right hand side widget providing you with real time data about most read articles from the category of articles you are viewig.

How to add the widget to your project?

1. add the "most_read_widget.php" file to your theme main folder.
2. in "function.php" add 

require_once( "most_read_widget.php" );

3. Go to "Appeariance" wordpress admin panel and choose "widgets" submenu. There you should have the plugin available.

What's so special about the widget?

1. It displays 10 most read posts from the category you are currently in.
2. It uses "category" taxonomy.
3. You can cache the results for the requests to chartbeat and manage the frequency from admin panel.
4. You can add the widget to any website without interfering with the widget source code - you can add your website's host and chartbeat api key in admin panel.


