FROM nginx:1.17.4

RUN apt-get update && apt-get install -y nginx-extras

CMD ["nginx", "-g", "daemon off;"]