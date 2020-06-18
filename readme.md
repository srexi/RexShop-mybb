A mybb 1.6.x & 1.8.x plugin to integration with [Rex Digital Shop](https://shop.rexdigital.group).

# Installation
## Mybb Setup
Upload the files to their apprpriate folders in mybb you can just drag the inc folder into the forum root folder and everything will place itself correctly.
Go to your mybb admin panel and click on "Configuration"(In the top navigation bar) and then on "Plugins"(In the left hand sidebar).
Find the RexShop plugin and click "Install & Activate".

Go to your mybb admin panel and click on "Configuration"(In the top navigation bar) and then on "Settings"(In the left hand sidebar).
Scroll down until you find "RexShop Setting".

Once there you will need to type in 3 values, which can be found in your rex shop configuration page.
All you need to do is go to your store on rex digital shop: [Click Here](https://shop.rexdigital.group/merchant)
Click on the cogwheel at the bottom left.
Then click on the developer tab in the sidebar.

Now copy & paste your client id, secret & api key to their appropriate settings in your mybb admin panel.
Make sure you don't share your api key & secret with anyone.

Next you just need to setup your products on rex digital shop.
When you're finished go into addons and create an addon called "Usergroup", set the type to "hidden".
And in the value field you write the id of the usergroup you would like to give to the user once they buy.
Then attach the addon to all your products which awards that usergroup.
You can create multiple addons with the same name if you want different producs to give different usergroups.

# Webhook URL
To setup the webhook url properly make sure you set the link to the root of your misc.php file in the root of your forum like so:
http://yoursite.com/forum/misc.php?payment=rexshop
