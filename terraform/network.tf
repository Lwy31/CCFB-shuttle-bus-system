resource "aws_vpc" "main" {
  cidr_block           = "10.0.0.0/16"
  enable_dns_hostnames = true
}

# --- Subnets ---
resource "aws_subnet" "public_a" {
  vpc_id                  = aws_vpc.main.id
  cidr_block              = "10.0.1.0/24"
  availability_zone       = "us-east-1a"
  map_public_ip_on_launch = true
}
resource "aws_subnet" "public_b" {
  vpc_id                  = aws_vpc.main.id
  cidr_block              = "10.0.2.0/24"
  availability_zone       = "us-east-1b"
  map_public_ip_on_launch = true
}
resource "aws_subnet" "private_a" {
  vpc_id            = aws_vpc.main.id
  cidr_block        = "10.0.10.0/24"
  availability_zone = "us-east-1a"
}
resource "aws_subnet" "private_b" {
  vpc_id            = aws_vpc.main.id
  cidr_block        = "10.0.11.0/24"
  availability_zone = "us-east-1b"
}

# --- Gateways & Routing ---
resource "aws_internet_gateway" "igw" { vpc_id = aws_vpc.main.id }
resource "aws_eip" "nat_eip" { domain = "vpc" }
resource "aws_nat_gateway" "nat" {
  allocation_id = aws_eip.nat_eip.id
  subnet_id     = aws_subnet.public_a.id
}

resource "aws_route_table" "public_rt" {
  vpc_id = aws_vpc.main.id
  route { 
            cidr_block = "0.0.0.0/0" 
            gateway_id = aws_internet_gateway.igw.id 
        }
}
resource "aws_route_table_association" "pub_a" { 
                                                    subnet_id = aws_subnet.public_a.id 
                                                    route_table_id = aws_route_table.public_rt.id 
                                                }
resource "aws_route_table_association" "pub_b" { 
                                                    subnet_id = aws_subnet.public_b.id 
                                                    route_table_id = aws_route_table.public_rt.id 
                                                }

resource "aws_route_table" "private_rt" {
  vpc_id = aws_vpc.main.id
  route { 
        cidr_block = "0.0.0.0/0"
        nat_gateway_id = aws_nat_gateway.nat.id 
        }
}
resource "aws_route_table_association" "priv_a" { 
    subnet_id = aws_subnet.private_a.id 
    route_table_id = aws_route_table.private_rt.id 
    }
resource "aws_route_table_association" "priv_b" { 
    subnet_id = aws_subnet.private_b.id 
    route_table_id = aws_route_table.private_rt.id 
    }
