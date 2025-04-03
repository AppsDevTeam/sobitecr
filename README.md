# SobIT ECR

docker run --rm -v "$PWD":/app --env-file .env -it --network host --pid=host php74-client php bin/client.php 1