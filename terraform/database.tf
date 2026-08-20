resource "random_password" "db_password" { 
    length = 16
    special = false 
 }

resource "aws_secretsmanager_secret" "db_secret" { name = "artvault-db-creds" }
resource "aws_secretsmanager_secret_version" "db_secret_val" {
  secret_id     = aws_secretsmanager_secret.db_secret.id
  secret_string = jsonencode({ username = "admin", password = random_password.db_password.result, host = aws_db_instance.db.address })
}

resource "aws_db_subnet_group" "db_subnets" {
  name       = "artvault-db-subnet-group"
  subnet_ids = [aws_subnet.private_a.id, aws_subnet.private_b.id]
}

resource "aws_db_instance" "db" {
  allocated_storage      = 20
  engine                 = "mysql"
  instance_class         = "db.t3.micro"
  db_name                = "artvault_db"
  username               = "admin"
  password               = random_password.db_password.result
  db_subnet_group_name   = aws_db_subnet_group.db_subnets.name
  vpc_security_group_ids = [aws_security_group.rds_sg.id]
  skip_final_snapshot    = true
  publicly_accessible    = false
}
