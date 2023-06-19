
all:
	make update
	make archive

update:
	git pull

archive:
	zip -r bloompay.zip bloompay/
	
